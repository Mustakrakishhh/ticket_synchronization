<?php

declare(strict_types=1);

namespace App\Sync;

use App\Domain\ServiceType;
use App\Domain\TravelDocumentUnavailableException;
use App\Domain\TravelItem;
use App\Provider\TravelDocumentProviderInterface;
use App\Provider\TravelDocumentCollectionProviderInterface;

final class AdditionalDocumentSync
{
    public function collect(TravelDocumentProviderInterface $provider, TravelItem $base, array $previous, string $target, bool $force): array
    {
        if (($previous['target'] ?? '') !== $target) {
            $previous = [];
        }
        $records = $base->data['_additional_items'] ?? [];
        $signature = hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
        $result = ['state' => $previous, 'value' => null, 'uploads' => 0, 'errors' => [], 'unavailable' => 0];
        if (!$force && ($previous['signature'] ?? '') === $signature
            && ($previous['target'] ?? '') === $target && ($previous['status'] ?? '') === 'available'
        ) {
            return $result;
        }
        $documents = [];
        foreach ($records as $externalId => $record) {
            $data = $record['data'];
            if (in_array((string) ($data['status_code'] ?? ''), ['3', '4', '5', '8', '9', '10', '11', '13'], true)) {
                continue;
            }
            try {
                $item = new TravelItem($base->provider, $externalId, ServiceType::ADDITIONAL_SERVICE, $data);
                $itemDocuments = $provider instanceof TravelDocumentCollectionProviderInterface
                    ? $provider->ticketDocuments($item)
                    : array_filter([$provider->ticketDocument($item)]);
                if ($itemDocuments === []) {
                    $result['unavailable']++;
                } else {
                    foreach ($itemDocuments as $document) {
                        $documents[$document->checksum()] = $document;
                    }
                }
            } catch (TravelDocumentUnavailableException) {
                $result['unavailable']++;
            } catch (\Throwable $exception) {
                $result['errors'][] = $externalId . ': ' . $exception->getMessage();
            }
        }
        ksort($documents);
        $checksum = hash('sha256', implode(':', array_keys($documents)));
        $status = $result['errors'] !== [] ? 'error' : ($result['unavailable'] > 0 ? 'unavailable' : 'available');
        $result['state'] = [
            'target' => $target, 'signature' => $signature, 'status' => $status,
            'checksum' => $previous['checksum'] ?? null, 'checked_at' => gmdate(DATE_ATOM),
        ];
        // Do not replace an already uploaded bundle by an incomplete response.
        if ($status !== 'available' && !empty($previous['checksum'])) {
            return $result;
        }
        if ($documents !== [] || $status === 'available') {
            $result['state']['checksum'] = $checksum;
            if ($force || ($previous['target'] ?? '') !== $target || ($previous['checksum'] ?? '') !== $checksum) {
                $result['value'] = array_values(array_map(static fn ($document): array => $document->bitrixValue(), $documents));
                $result['uploads'] = count($documents);
            }
        }
        return $result;
    }
}
