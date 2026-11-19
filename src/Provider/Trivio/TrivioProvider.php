<?php

declare(strict_types=1);

namespace App\Provider\Trivio;

use App\Domain\ServiceType;
use App\Domain\TravelDocument;
use App\Domain\TravelDocumentUnavailableException;
use App\Domain\TravelItem;
use App\Provider\TravelDocumentCollectionProviderInterface;
use App\Provider\TravelProviderInterface;

final class TrivioProvider implements TravelProviderInterface, TravelDocumentCollectionProviderInterface
{
    private TrivioClient $client;
    private ?TrivioJsonClient $historyClient;
    private TrivioNormalizer $normalizer;

    public function __construct(
        array $credentials,
        ?TrivioClient $client = null,
        ?TrivioJsonClient $historyClient = null,
    )
    {
        $this->client = $client ?? new TrivioClient(
            (string) ($credentials['token'] ?? ''),
        );
        $login = trim((string) ($credentials['login'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');
        $this->historyClient = $historyClient
            ?? ($login !== '' && $password !== '' ? new TrivioJsonClient($login, $password) : null);
        $this->normalizer = new TrivioNormalizer();
    }

    public function code(): string
    {
        return 'trivio';
    }

    public function testConnection(): void
    {
        $this->client->testConnection();
        $this->historyClient?->testConnection();
    }

    public function fetch(?string $cursor, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var array<string, TravelItem> $items */
        $items = [];
        $transactions = $this->client->transactions();
        $historyOrderCount = 0;
        $historyOrderErrors = [];
        $completeOrderIds = [];
        usort($transactions, static fn (array $left, array $right): int =>
            (int) ($left['transaction_id'] ?? 0) <=> (int) ($right['transaction_id'] ?? 0)
        );
        foreach ($transactions as $transaction) {
            $item = $this->normalizer->normalizeTransaction($transaction);
            if ($item !== null) {
                $items[$item->externalId] = $item;
            }
        }

        if ($this->historyClient !== null) {
            foreach ($this->historyClient->detailedOrders($from, $to) as $detailedOrder) {
                $historyOrderCount++;
                if (!empty($detailedOrder['order']['order_id'])) {
                    $completeOrderIds[] = (string) $detailedOrder['order']['order_id'];
                }
                $historyItems = $this->normalizer->normalize($detailedOrder);
                $historyIds = array_fill_keys(array_map(static fn (TravelItem $item): string => $item->externalId, $historyItems), true);
                foreach ($items as $externalId => $queuedItem) {
                    if ($queuedItem->serviceType === ServiceType::ADDITIONAL_SERVICE
                        && (string) ($queuedItem->data['order_id'] ?? '') === (string) ($detailedOrder['order']['order_id'] ?? '')
                        && !isset($historyIds[$externalId])
                    ) {
                        unset($items[$externalId]);
                    }
                }
                foreach ($historyItems as $item) {
                    // getOrder contains the current complete state and richer
                    // fields, but the Integration API is the authoritative source
                    // for refund amounts and penalties. Preserve those fields when
                    // both responses describe the same ticket.
                    $item = $this->mergeTransactionFinancials($item, $items[$item->externalId] ?? null);
                    $items[$item->externalId] = $item;
                }
            }
            foreach ($this->historyClient->orderErrors() as $orderId => $error) {
                $historyOrderErrors[] = str_contains($error, (string) $orderId)
                    ? $error
                    : "Заказ Trivio {$orderId}: {$error}";
            }
        }

        return [
            'items' => array_values($items),
            'sourceMode' => $this->historyClient !== null ? 'queue_and_history' : 'provider_queue',
            'queueTransactions' => count($transactions),
            'historyOrders' => $historyOrderCount,
            'historyOrderErrors' => count($historyOrderErrors),
            'sourceErrors' => $historyOrderErrors,
            'completeOrderIds' => $completeOrderIds,
            // Read without integrationFeedback: acknowledgement belongs to 1C.
            // Repeated operations are reconciled using local IDs/checksums.
            // transaction_id is not a documented polling cursor.
            'nextCursor' => null,
        ];
    }

    public function ticketDocument(TravelItem $item): ?TravelDocument
    {
        if (!empty($item->data['trivio_service_files'])
            && !in_array($item->serviceType, [ServiceType::AIR, ServiceType::RAIL, ServiceType::AEROEXPRESS], true)
        ) {
            return $this->ticketDocuments($item)[0] ?? null;
        }
        if (!in_array($item->serviceType, [ServiceType::AIR, ServiceType::RAIL, ServiceType::AEROEXPRESS, ServiceType::ADDITIONAL_SERVICE], true)) {
            return null;
        }
        $serviceId = (int) ($item->data['trivio_service_id'] ?? $item->data['service_id'] ?? 0);
        $ticketId = (int) ($item->data['trivio_ticket_id'] ?? 0);
        if ($serviceId <= 0 || $ticketId <= 0) {
            return null;
        }
        try {
            $document = $this->client->printBlank($serviceId, $ticketId);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'Не найден билет по указанным параметрам')
                || str_contains(strtolower($message), 'ticket not found')
            ) {
                throw new TravelDocumentUnavailableException(
                    'Маршрутная квитанция пока недоступна в Trivio.',
                    previous: $exception,
                );
            }
            throw $exception;
        }

        return new TravelDocument(
            $document['filename'],
            $document['content'],
            $document['contentType'],
        );
    }

    public function ticketDocuments(TravelItem $item): array
    {
        $files = $item->data['trivio_service_files'] ?? [];
        if ($files === []) {
            $document = $this->ticketDocument($item);
            return $document !== null ? [$document] : [];
        }
        if ($this->historyClient === null) {
            throw new TravelDocumentUnavailableException('Для скачивания вложений Trivio укажите логин и пароль Client API.');
        }
        $documents = [];
        foreach ($files as $file) {
            if ((string) ($file['service_file_service_id'] ?? '') !== (string) ($item->data['service_id'] ?? '')) {
                throw new \RuntimeException('Вложение Trivio не принадлежит синхронизируемой услуге.');
            }
            $documents[] = $this->historyClient->downloadServiceFile((int) ($file['service_file_id'] ?? 0));
        }
        return $documents;
    }

    private function mergeTransactionFinancials(TravelItem $history, ?TravelItem $transaction): TravelItem
    {
        if ($transaction === null || !in_array(
            (string) ($transaction->data['expense_status'] ?? ''),
            ['refunded', 'cancelled', 'exchanged'],
            true,
        )) {
            return $history;
        }

        $data = $history->data;
        foreach ([
            'expense_status', 'refund_amount', 'penalty_amount', 'non_refundable_amount',
            'original_amount', 'net_amount', 'calculation_status', 'trivio_transaction_id',
        ] as $field) {
            if (array_key_exists($field, $transaction->data)) {
                $data[$field] = $transaction->data[$field];
            }
        }
        $data['status'] = $transaction->data['status'] ?? $data['status'] ?? null;
        $data['status_code'] = $transaction->data['status_code'] ?? $data['status_code'] ?? null;
        $data['updated_at'] = $transaction->data['updated_at'] ?? $data['updated_at'] ?? null;

        return new TravelItem($history->provider, $history->externalId, $history->serviceType, $data);
    }
}
