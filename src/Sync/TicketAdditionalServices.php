<?php

declare(strict_types=1);

namespace App\Sync;

use App\Domain\ServiceType;
use App\Domain\TravelItem;

final class TicketAdditionalServices
{
    /** @return array<string, list<TravelItem>> */
    public function associations(string $provider, array $items): array
    {
        $groups = (new ExpenseAggregator())->group($provider, $items);
        $result = [];
        foreach ($groups['baseItems'] as $index => $item) {
            $result[$item->externalId] = $groups['attachments'][$index];
        }
        return $result;
    }

    /** Preserve previously observed extras when an incremental queue omits them. */
    public function enrich(TravelItem $base, array $additional, array $previous, bool $complete): TravelItem
    {
        if (!ServiceType::requiresTicketNumber($base->serviceType)) {
            return $base;
        }
        $known = $complete ? [] : ($previous['_additional_items'] ?? []);
        $known = is_array($known) ? $known : [];
        foreach ($additional as $item) {
            $data = $item->data;
            unset($data['raw_json']);
            $known[$item->externalId] = ['provider' => $item->provider, 'data' => $data];
        }
        ksort($known);
        $labels = [];
        $amount = 0.0;
        foreach ($known as $record) {
            $data = $record['data'];
            // Refunded/cancelled extras remain known, but are not active purchased services.
            if (in_array((string) ($data['status_code'] ?? ''), ['3', '4', '5', '8', '9', '10', '11', '13'], true)) {
                continue;
            }
            $label = trim((string) ($data['additional_service_name'] ?? $data['route'] ?? ''));
            $label = trim((string) (preg_split('/\s*(?:→|;|\|)\s*/u', $label, 2)[0] ?? ''));
            $labels[] = $label !== '' ? $label : 'Дополнительная услуга';
            if (is_numeric($data['amount'] ?? null)) {
                $amount += (float) $data['amount'];
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);
        $data = $base->data;
        if (!$complete && is_numeric($data['amount'] ?? null) && is_numeric($previous['amount'] ?? null)
            && round((float) $data['amount'], 2) === round((float) $previous['amount'] - (float) ($previous['additional_amount'] ?? 0), 2)
        ) {
            $data['ticket_amount'] ??= $previous['ticket_amount'] ?? null;
            $data['service_fee'] ??= $previous['service_fee'] ?? null;
        }
        $data['_additional_items'] = $known;
        $data['additional_service_types'] = $labels;
        $data['additional_amount'] = round($amount, 2);
        $data['ticket_amount'] = $data['ticket_amount'] ?? $data['amount'] ?? null;
        if (is_numeric($data['amount'] ?? null)) {
            $data['amount'] = round((float) $data['amount'] + $amount, 2);
        }
        return new TravelItem($base->provider, $base->externalId, $base->serviceType, $data);
    }
}
