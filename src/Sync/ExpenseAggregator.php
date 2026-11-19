<?php

declare(strict_types=1);

namespace App\Sync;

use App\Domain\ExpenseRecord;
use App\Domain\ServiceType;
use App\Domain\TravelItem;

final class ExpenseAggregator
{
    /**
     * @param list<TravelItem> $items
     * @return array{records: list<ExpenseRecord>, unmatchedAdditional: int}
     */
    public function aggregate(string $provider, string $supplier, array $items): array
    {
        $groups = $this->group($provider, $items);
        $records = [];
        foreach ($groups['baseItems'] as $index => $base) {
            $records[] = $this->record($provider, $supplier, $base, $groups['attachments'][$index]);
        }
        return ['records' => $records, 'unmatchedAdditional' => $groups['unmatchedAdditional']];
    }

    /** @return array{baseItems: list<TravelItem>, attachments: array<int, list<TravelItem>>, unmatchedAdditional: int} */
    public function group(string $provider, array $items): array
    {
        $baseItems = [];
        $additionalItems = [];
        foreach ($items as $item) {
            if (!$item instanceof TravelItem) {
                continue;
            }
            if ($item->serviceType === ServiceType::ADDITIONAL_SERVICE) {
                $additionalItems[] = $item;
                continue;
            }
            $isTicketExpense = ServiceType::requiresTicketNumber($item->serviceType)
                && ($provider === 'aviasales'
                    || trim((string) ($item->data['ticket_number'] ?? '')) !== '');
            $isAccommodationExpense = $item->serviceType === ServiceType::ACCOMMODATION;
            if ($isTicketExpense || $isAccommodationExpense) {
                $baseItems[] = $item;
            }
        }

        $attachments = array_fill(0, count($baseItems), []);
        $unmatched = 0;
        foreach ($additionalItems as $additional) {
            $match = $this->matchBaseIndex($additional, $baseItems);
            if ($match === null) {
                $unmatched++;
                continue;
            }
            $attachments[$match][] = $additional;
        }

        return ['baseItems' => $baseItems, 'attachments' => $attachments, 'unmatchedAdditional' => $unmatched];
    }

    /** @param list<TravelItem> $baseItems */
    private function matchBaseIndex(TravelItem $additional, array $baseItems): ?int
    {
        $baseItems = array_filter($baseItems, fn (TravelItem $base): bool =>
            $base->provider === $additional->provider
            && strtoupper((string) ($additional->data['currency'] ?? 'RUB')) === strtoupper((string) ($base->data['currency'] ?? 'RUB'))
            && ($this->key($additional->data['order_id'] ?? null) === ''
                || $this->key($base->data['order_id'] ?? null) === ''
                || $this->key($additional->data['order_id']) === $this->key($base->data['order_id']))
        );
        $ticketNumbers = $this->ticketKeys($additional->data['base_ticket_number'] ?? $additional->data['ticket_number'] ?? null);
        if ($ticketNumbers !== []) {
            $matches = $this->matchingIndexes(
                $baseItems,
                fn (TravelItem $base): bool => ServiceType::requiresTicketNumber($base->serviceType)
                    && array_intersect(
                        $this->ticketKeys($base->data['ticket_number'] ?? null),
                        $ticketNumbers,
                    ) !== [],
            );
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        // A documented parent ticket number must not silently fall back to another ticket.
        if (trim((string) ($additional->data['base_ticket_number'] ?? '')) !== '') {
            return null;
        }
        $serviceId = $this->key($additional->data['base_service_id'] ?? $additional->data['service_id'] ?? null);
        if ($serviceId !== '') {
            $matches = $this->matchingIndexes(
                $baseItems,
                fn (TravelItem $base): bool => ServiceType::requiresTicketNumber($base->serviceType)
                    && $this->key($base->data['service_id'] ?? null) === $serviceId,
            );
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        $orderId = $this->key($additional->data['order_id'] ?? null);
        $passenger = $this->key($additional->data['passenger'] ?? null);
        if ($orderId !== '') {
            $matches = $this->matchingIndexes(
                $baseItems,
                function (TravelItem $base) use ($orderId, $passenger): bool {
                    if (!ServiceType::requiresTicketNumber($base->serviceType)) {
                        return false;
                    }
                    if ($this->key($base->data['order_id'] ?? null) !== $orderId) {
                        return false;
                    }
                    return $passenger === '' || $this->key($base->data['passenger'] ?? null) === $passenger;
                },
            );
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    /**
     * @param list<TravelItem> $items
     * @return list<int>
     */
    private function matchingIndexes(array $items, callable $predicate): array
    {
        $matches = [];
        foreach ($items as $index => $item) {
            if ($predicate($item)) {
                $matches[] = $index;
            }
        }
        return $matches;
    }

    /** @param list<TravelItem> $additional */
    private function record(string $provider, string $supplier, TravelItem $base, array $additional): ExpenseRecord
    {
        $baseAmount = $this->amount($base->data['amount'] ?? null);
        $additionalAmount = 0.0;
        $hasAdditionalAmount = false;
        $serviceIds = [];
        $baseServiceId = trim((string) ($base->data['service_id'] ?? ''));
        if ($baseServiceId !== '') {
            $serviceIds[] = $baseServiceId;
        }
        $labels = [];
        foreach ($additional as $item) {
            $amount = $this->amount($item->data['amount'] ?? null);
            if ($amount !== null) {
                $additionalAmount += $amount;
                $hasAdditionalAmount = true;
            }
            $serviceId = trim((string) ($item->data['service_id'] ?? ''));
            if ($serviceId !== '') {
                $serviceIds[] = $serviceId;
            }
            $labels[] = $this->additionalLabel($item);
        }
        $serviceIds = array_values(array_unique($serviceIds));
        $labels = array_values(array_unique(array_filter($labels)));
        $grossAmount = ($baseAmount ?? 0.0) + $additionalAmount;
        $hasTotal = $baseAmount !== null || $hasAdditionalAmount;
        $expenseStatus = $this->expenseStatus($base->data);
        $refundAmount = $this->absoluteAmount($base->data['refund_amount'] ?? null);
        $penaltyAmount = $this->absoluteAmount($base->data['penalty_amount'] ?? null);
        $nonRefundableAmount = $this->absoluteAmount($base->data['non_refundable_amount'] ?? null);
        $originalAmount = $this->amount($base->data['original_amount'] ?? null);
        $netAmount = $this->amount($base->data['net_amount'] ?? null);
        $calculationStatus = trim((string) ($base->data['calculation_status'] ?? ''));

        if ($expenseStatus === 'active') {
            // The provider's own net/original amount does not include separately purchased extras.
            if ($hasAdditionalAmount) {
                $originalAmount = $originalAmount !== null ? $originalAmount + $additionalAmount : null;
                $netAmount = $netAmount !== null ? $netAmount + $additionalAmount : null;
            }
            $originalAmount ??= $hasTotal ? $grossAmount : null;
            $refundAmount ??= 0.0;
            $netAmount ??= $hasTotal ? $grossAmount : null;
            $calculationStatus = $calculationStatus !== '' ? $calculationStatus : 'source_amount';
        } elseif (in_array($expenseStatus, ['refunded', 'cancelled', 'exchanged'], true)) {
            // Additional services are separate purchases. Unless the provider
            // sends their own refund operations, they remain part of the expense.
            if ($hasAdditionalAmount) {
                $originalAmount = $originalAmount !== null ? $originalAmount + $additionalAmount : null;
                $netAmount = $netAmount !== null ? $netAmount + $additionalAmount : null;
            }
            if ($originalAmount === null && $refundAmount !== null) {
                $originalAmount = $refundAmount
                    + ($penaltyAmount ?? 0.0)
                    + ($nonRefundableAmount ?? 0.0)
                    + $additionalAmount;
            }
            if ($netAmount === null && $originalAmount !== null && $refundAmount !== null) {
                $netAmount = max(0.0, $originalAmount - $refundAmount);
            }
            if ($netAmount === null && ($penaltyAmount !== null || $nonRefundableAmount !== null)) {
                $netAmount = ($penaltyAmount ?? 0.0) + ($nonRefundableAmount ?? 0.0);
            }
            $calculationStatus = $calculationStatus !== ''
                ? $calculationStatus
                : ($netAmount !== null ? 'calculated' : 'refund_amount_unknown');
        }

        $effectiveAmount = $netAmount ?? ($hasTotal ? $grossAmount : null);

        return new ExpenseRecord($provider, 'expense:' . $provider . ':' . $base->externalId, [
            'ticket_external_id' => $base->externalId,
            'supplier' => $supplier,
            'service_type' => $base->serviceType,
            'service_type_label' => ServiceType::all()[$base->serviceType] ?? ServiceType::all()[ServiceType::OTHER],
            'ticket_number' => $base->data['ticket_number'] ?? null,
            'service_id' => $base->data['service_id'] ?? null,
            'service_ids' => $serviceIds !== [] ? implode(', ', $serviceIds) : null,
            'order_id' => $base->data['order_id'] ?? null,
            // Keep the legacy amount field as the actual expense so existing
            // Bitrix24 mappings start receiving the corrected value automatically.
            'amount' => $effectiveAmount,
            'gross_amount' => $hasTotal ? $grossAmount : null,
            'original_amount' => $originalAmount,
            'refund_amount' => $refundAmount,
            'penalty_amount' => $penaltyAmount,
            'non_refundable_amount' => $nonRefundableAmount,
            'net_amount' => $netAmount,
            'expense_status' => $expenseStatus,
            'expense_status_label' => $this->expenseStatusLabel($expenseStatus),
            'calculation_status' => $calculationStatus !== '' ? $calculationStatus : 'source_amount',
            'calculation_status_label' => $this->calculationStatusLabel(
                $calculationStatus !== '' ? $calculationStatus : 'source_amount',
            ),
            'provider_status' => $base->data['status'] ?? null,
            'base_amount' => $baseAmount,
            'additional_amount' => $hasAdditionalAmount ? $additionalAmount : null,
            'currency' => $base->data['currency'] ?? 'RUB',
            'passenger' => $base->data['passenger'] ?? null,
            'additional_services' => $labels !== [] ? implode(', ', $labels) : null,
            'service_url' => $base->data['service_url'] ?? null,
            'route' => $base->data['route'] ?? null,
            'departure_at' => $base->data['departure_at'] ?? null,
            'hotel_name' => $base->data['hotel_name'] ?? null,
            'hotel_address' => $base->data['hotel_address'] ?? null,
            'room_name' => $base->data['room_name'] ?? null,
            'check_in' => $base->data['check_in'] ?? null,
            'check_out' => $base->data['check_out'] ?? null,
            'created_at' => $base->data['created_at'] ?? null,
            'updated_at' => $this->latestUpdatedAt($base, $additional),
        ]);
    }

    /** @param list<TravelItem> $additional */
    private function latestUpdatedAt(TravelItem $base, array $additional): mixed
    {
        $values = [$base->data['updated_at'] ?? $base->data['created_at'] ?? null];
        foreach ($additional as $item) {
            $values[] = $item->data['updated_at'] ?? $item->data['created_at'] ?? null;
        }
        $values = array_values(array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== ''));
        if ($values === []) {
            return null;
        }
        usort($values, static function (mixed $left, mixed $right): int {
            try {
                return (new \DateTimeImmutable((string) $left)) <=> (new \DateTimeImmutable((string) $right));
            } catch (\Throwable) {
                return strcmp((string) $left, (string) $right);
            }
        });
        return $values[array_key_last($values)];
    }

    private function additionalLabel(TravelItem $item): string
    {
        $route = trim((string) ($item->data['route'] ?? ''));
        if ($route !== '') {
            $parts = preg_split('/\s*(?:→|;|\|)\s*/u', $route, 2);
            $label = trim((string) ($parts[0] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }
        return 'Дополнительная услуга';
    }

    private function amount(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    private function absoluteAmount(mixed $value): ?float
    {
        $amount = $this->amount($value);
        return $amount !== null ? abs($amount) : null;
    }

    private function expenseStatus(array $data): string
    {
        $explicit = strtolower(trim((string) ($data['expense_status'] ?? '')));
        if (in_array($explicit, ['active', 'refunded', 'cancelled', 'exchanged'], true)) {
            return $explicit;
        }

        $code = strtolower(trim((string) ($data['status_code'] ?? '')));
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if ($code === '5' || str_contains($status, 'refund') || str_contains($status, 'возврат')) {
            return 'refunded';
        }
        if ($code === '8' || str_contains($status, 'exchange') || str_contains($status, 'обмен')) {
            return 'exchanged';
        }
        if (in_array($code, ['3', '4', '9', '11', '13', 'cancelled', 'canceled'], true)) {
            return 'cancelled';
        }
        foreach (['cancel', 'annul', 'void', 'отмен', 'аннулир'] as $marker) {
            if (str_contains($status, $marker)) {
                return 'cancelled';
            }
        }
        return 'active';
    }

    private function expenseStatusLabel(string $status): string
    {
        return match ($status) {
            'refunded' => 'Возврат',
            'cancelled' => 'Отменено',
            'exchanged' => 'Обмен',
            default => 'Действует',
        };
    }

    private function calculationStatusLabel(string $status): string
    {
        return match ($status) {
            'provider_exact' => 'Точный расчёт по данным провайдера',
            'calculated' => 'Рассчитано из суммы покупки и возврата',
            'refund_amount_unknown' => 'Сумма возврата не передана — требуется проверка',
            default => 'Сумма провайдера',
        };
    }

    /** @return list<string> */
    private function ticketKeys(mixed $value): array
    {
        $parts = preg_split('/[,;\n]+/u', (string) $value) ?: [];
        return array_values(array_unique(array_filter(array_map(
            fn (string $part): string => $this->key($part),
            $parts,
        ))));
    }

    private function key(mixed $value): string
    {
        $value = trim((string) $value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        return $value ?? '';
    }
}
