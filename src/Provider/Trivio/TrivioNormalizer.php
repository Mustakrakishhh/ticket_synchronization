<?php

declare(strict_types=1);

namespace App\Provider\Trivio;

use App\Domain\ServiceType;
use App\Domain\TravelItem;
use App\Provider\AdditionalServiceFilter;
use App\Provider\ServiceUrlExtractor;

final class TrivioNormalizer
{
    public function normalizeTransaction(array $transaction): ?TravelItem
    {
        $isAdditionalService = AdditionalServiceFilter::matches(
            $transaction['transaction_type'] ?? null,
            $transaction['transaction_type_name'] ?? null,
            $transaction['service_name'] ?? null,
            $transaction['title'] ?? null,
            $transaction['description'] ?? null,
            $transaction['itinerary_desc'] ?? null,
        );

        $transactionId = (int) ($transaction['transaction_id'] ?? 0);
        $ticketId = (int) ($transaction['ticket_id'] ?? 0);
        if ($transactionId <= 0 && $ticketId <= 0) {
            return null;
        }
        $identityType = ServiceType::normalize($transaction['transaction_type'] ?? null);
        $type = $isAdditionalService ? ServiceType::ADDITIONAL_SERVICE : $identityType;
        $identity = $ticketId > 0 ? $ticketId : $transactionId;
        $first = trim((string) ($transaction['first_name'] ?? ''));
        $last = trim((string) ($transaction['sur_name'] ?? $transaction['last_name'] ?? ''));
        $middle = trim((string) ($transaction['middle_name'] ?? ''));
        $statusCode = (string) ($transaction['status'] ?? '');
        $status = trim((string) ($transaction['status_text'] ?? $transaction['tracking_status_text'] ?? ''));
        if ($status === '') {
            $status = $statusCode === '5' ? 'Возврат' : ($statusCode === '2' ? 'Покупка / обмен / доплата' : $statusCode);
        }
        $transactionAmount = $this->money($transaction['sum'] ?? null);
        $penaltyAmount = $this->firstMoney([
            $transaction['penalty_sum'] ?? null,
            $transaction['penalty'] ?? null,
        ]);
        $nonRefundableAmount = $this->sumMoney([
            $transaction['non_refundable_fare'] ?? null,
            $transaction['non_refundable_taxes'] ?? null,
        ]);
        $isRefund = $statusCode === '5';
        $refundAmount = $isRefund && $transactionAmount !== null ? abs($transactionAmount) : null;
        $retainedAmount = $isRefund
            ? ($penaltyAmount ?? 0.0) + ($nonRefundableAmount ?? 0.0)
            : null;
        $analytics = $transaction['order_analytics'] ?? null;
        $project = $this->analyticsLabel($analytics);
        $date = $transaction['transaction_date'] ?? null;
        $route = $transaction['itinerary_desc'] ?? null;
        [$departureLocation, $arrivalLocation] = $this->routeEndpoints($route);

        $orderId = $transaction['order_id'] ?? null;

        return new TravelItem('trivio', "{$identityType}:{$identity}", $type, [
            'order_id' => $orderId,
            'service_id' => $transaction['service_id'] ?? null,
            'ticket_number' => $transaction['number'] ?? null,
            'booking_number' => null,
            'service_url' => ServiceUrlExtractor::forProvider('trivio', $orderId, $transaction),
            'status' => $status,
            'status_code' => $statusCode,
            'passenger' => trim("{$last} {$first} {$middle}"),
            'passenger_first_name' => $first,
            'passenger_last_name' => $last,
            'passenger_middle_name' => $middle,
            'route' => $route,
            'departure_location' => $departureLocation,
            'arrival_location' => $arrivalLocation,
            'departure_at' => $transaction['itinerary_start'] ?? null,
            'arrival_at' => $transaction['itinerary_end'] ?? null,
            'amount' => $transactionAmount,
            'expense_status' => $isRefund ? 'refunded' : ($statusCode === '2' ? 'active' : null),
            'refund_amount' => $refundAmount,
            'penalty_amount' => $penaltyAmount,
            'non_refundable_amount' => $nonRefundableAmount,
            'original_amount' => $isRefund && $refundAmount !== null
                ? $refundAmount + $retainedAmount
                : $transactionAmount,
            'net_amount' => $isRefund ? $retainedAmount : $transactionAmount,
            'calculation_status' => $isRefund ? 'provider_exact' : 'source_amount',
            'currency' => 'RUB',
            'company_name' => null,
            'company_inn' => null,
            'hotel_name' => null,
            'hotel_address' => null,
            'room_name' => null,
            'created_at' => $date,
            'updated_at' => $date,
            'project' => $project,
            'trivio_transaction_id' => $transactionId,
            'trivio_service_id' => (int) ($transaction['service_id'] ?? 0),
            'trivio_ticket_id' => $ticketId,
            'additional_service_name' => $isAdditionalService ? $route : null,
            'base_ticket_number' => $isAdditionalService ? $this->baseTicketNumber($transaction, $route) : null,
            'raw_json' => json_encode($this->scrub($transaction), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return list<TravelItem> */
    public function normalize(array $detailed): array
    {
        $order = is_array($detailed['order'] ?? null) ? $detailed['order'] : [];
        $currency = (string) ($detailed['_currency'] ?? 'RUB');
        $items = [];

        $avia = $this->indexBy($detailed['avia_services'] ?? [], ['service_id', 'avia_service_id']);
        $aviaPrices = $this->ticketPrices($detailed['avia_service_tickets'] ?? [], $avia, 'avia_ticket_', 'avia_service_');
        foreach ($this->rows($detailed['avia_service_tickets'] ?? []) as $ticket) {
            $service = $avia[(string) ($ticket['service_id'] ?? $ticket['avia_ticket_service_id'] ?? '')] ?? [];
            $departureLocation = $this->firstNonEmpty([
                $service['avia_service_route_departure'] ?? null,
                $ticket['avia_ticket_desc_departure'] ?? null,
                $this->firstSegment($service, 'segment_departure_city_name'),
                $this->firstSegment($service, 'segment_departure_airport'),
            ]);
            $arrivalLocation = $this->firstNonEmpty([
                $service['avia_service_route_arrival'] ?? null,
                $ticket['avia_ticket_desc_arrival'] ?? null,
                $this->firstSegment($service, 'segment_arrival_city_name', true),
                $this->firstSegment($service, 'segment_arrival_airport', true),
            ]);
            $items[] = $this->item('air', $ticket['avia_ticket_id'] ?? null, $order, $service, $ticket, $currency, [
                'ticket_number' => $ticket['avia_ticket_number'] ?? null,
                'booking_number' => $this->firstNonEmpty([
                    $service['avia_service_pnr'] ?? null,
                    $service['avia_service_booking_code'] ?? null,
                ]),
                'route' => $this->firstNonEmpty([
                    $service['avia_service_route'] ?? null,
                    $ticket['avia_ticket_description'] ?? null,
                    $this->routeLabel($departureLocation, $arrivalLocation),
                ]),
                'departure_location' => $departureLocation,
                'arrival_location' => $arrivalLocation,
                'departure_at' => $this->mostPreciseDateTime([
                    $ticket['avia_ticket_desc_departure_date'] ?? null,
                    $this->firstSegment($service, 'segment_departure_time'),
                ]),
                'arrival_at' => $this->mostPreciseDateTime([
                    $ticket['avia_ticket_desc_arrival_date'] ?? null,
                    $this->firstSegment($service, 'segment_arrival_time', true),
                ]),
                'amount' => $ticket['avia_ticket_totalcost'] ?? $service['total_price'] ?? null,
                'status' => $ticket['avia_ticket_status'] ?? $service['service_current_status'] ?? null,
                'trivio_ticket_id' => $ticket['avia_ticket_id'] ?? null,
                ...($aviaPrices[(string) ($ticket['avia_ticket_id'] ?? '')] ?? []),
            ]);
        }

        $roomers = $this->rows($detailed['hotel_multi_roomers'] ?? []);
        foreach ($this->rows($detailed['hotel_multi_services'] ?? []) as $service) {
            $id = $service['hotel_service_id'] ?? $service['service_id'] ?? null;
            $matchIds = array_map('strval', array_filter([
                $id,
                $service['service_id'] ?? null,
                $service['hotel_service_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            $people = array_values(array_filter($roomers, static fn (array $row): bool => in_array(
                (string) ($row['hotel_roomer_service_id'] ?? $row['hotel_roomer_hotel_service_id'] ?? ''),
                $matchIds,
                true,
            )));
            $person = $people[0] ?? [];
            $hotel = is_array($service['hotel_multi_info'] ?? null) ? $service['hotel_multi_info'] : [];
            $items[] = $this->item('accommodation', $id, $order, $service, $person, $currency, [
                'hotel_name' => $hotel['hotel_name'] ?? null,
                'hotel_address' => $hotel['hotel_address'] ?? null,
                'room_name' => $service['hotel_service_room_name'] ?? null,
                'route' => $hotel['hotel_city'] ?? $hotel['hotel_country'] ?? null,
                'check_in' => $service['hotel_service_check_in_date'] ?? null,
                'check_out' => $service['hotel_service_check_out_date'] ?? null,
                'amount' => $service['total_price'] ?? $service['hotel_service_price'] ?? null,
                'status' => $service['service_current_status'] ?? null,
            ]);
        }

        $train = $this->indexBy($detailed['train_services'] ?? [], ['service_id', 'train_ufs_service_id']);
        $trainPrices = $this->ticketPrices($detailed['train_tickets'] ?? [], $train, 'train_ufs_ticket_', 'train_ufs_service_');
        foreach ($this->rows($detailed['train_tickets'] ?? []) as $ticket) {
            $service = $train[(string) ($ticket['service_id'] ?? $ticket['train_ufs_ticket_service_id'] ?? '')] ?? [];
            $trainRoute = $service['train_ufs_service_route'] ?? null;
            [$trainDeparture, $trainArrival] = $this->routeEndpoints($trainRoute);
            $items[] = $this->item('rail', $ticket['train_ufs_ticket_id'] ?? null, $order, $service, $ticket, $currency, [
                'ticket_number' => $ticket['train_ufs_ticket_number'] ?? null,
                'booking_number' => $service['train_ufs_service_pnr'] ?? null,
                'route' => $trainRoute,
                'departure_location' => $trainDeparture,
                'arrival_location' => $trainArrival,
                'departure_at' => $service['train_ufs_result_departure_date'] ?? null,
                'arrival_at' => $service['train_ufs_result_arrival_date'] ?? null,
                'amount' => $ticket['train_ufs_ticket_totalcost'] ?? $service['total_price'] ?? null,
                'status' => $ticket['train_ufs_ticket_status'] ?? $service['service_current_status'] ?? null,
                'trivio_ticket_id' => $ticket['train_ufs_ticket_id'] ?? null,
                ...($trainPrices[(string) ($ticket['train_ufs_ticket_id'] ?? '')] ?? []),
            ]);
        }

        $items = array_merge(
            $items,
            $this->simpleServices($detailed, $order, $currency, 'transfer', 'transfer_services', 'transfer_service_id', [
                'route' => ['transfer_route_name'],
                'departure_at' => ['transfer_service_param_departure_date'],
                'arrival_at' => ['transfer_service_param_arrival_date'],
                'amount' => ['total_price', 'transfer_service_total_price'],
            ]),
            $this->simpleServices($detailed, $order, $currency, 'taxi', 'taxi_services', 'taxi_service_id', [
                'route' => ['taxi_service_from', 'taxi_service_to'],
                'departure_at' => ['taxi_service_start_date'],
                'arrival_at' => ['taxi_service_end_date'],
                'amount' => ['taxi_service_total', 'taxi_service_price'],
            ]),
            $this->simpleServices($detailed, $order, $currency, 'other', 'arbitrary_services', 'arbitrary_service_id', [
                'route' => ['title', 'description'],
                'amount' => ['total_price', 'arbitrary_service_totalcost'],
            ]),
            $this->simpleServices($detailed, $order, $currency, 'vip_hall', 'vip_hall_services', 'vip_hall_service_id', [
                'route' => ['airport_name', 'city_name'],
                'departure_at' => ['vip_hall_service_flight_datetime'],
                'amount' => ['total_price', 'vip_hall_service_cost'],
            ]),
        );

        // Bus journey dates belong to the service, not to the passenger's ticket.
        $bus = $this->indexBy($detailed['bus_services'] ?? [], ['service_id', 'bus_service_id']);
        $busPrices = $this->ticketPrices($detailed['bus_tickets'] ?? [], $bus, 'bus_ticket_', 'bus_service_');
        foreach ($this->rows($detailed['bus_tickets'] ?? []) as $ticket) {
            $service = $bus[(string) ($ticket['bus_ticket_service_id'] ?? $ticket['service_id'] ?? '')] ?? [];
            $service['service_id'] ??= $ticket['service_id'] ?? $service['bus_service_id'] ?? $ticket['bus_ticket_service_id'] ?? null;
            $departureLocation = $this->firstNonEmpty([
                $service['service_city_from'] ?? null,
                $service['bus_result_from'] ?? null,
            ]);
            $arrivalLocation = $this->firstNonEmpty([
                $service['service_city_to'] ?? null,
                $service['bus_result_to'] ?? null,
            ]);
            $items[] = $this->item('bus', $ticket['bus_ticket_id'] ?? null, $order, $service, $ticket, $currency, [
                'ticket_number' => $ticket['bus_ticket_number'] ?? null,
                'route' => $this->firstNonEmpty([
                    $service['bus_service_race_name'] ?? null,
                    $ticket['bus_ticket_description'] ?? null,
                    $this->routeLabel($departureLocation, $arrivalLocation),
                ]),
                'departure_location' => $departureLocation,
                'arrival_location' => $arrivalLocation,
                'departure_at' => $service['bus_result_departure_date'] ?? null,
                'arrival_at' => $service['bus_result_arrival_date'] ?? null,
                'amount' => $ticket['bus_ticket_total_cost'] ?? null,
                'status' => $ticket['bus_ticket_status'] ?? $service['service_current_status'] ?? null,
                'trivio_ticket_id' => $ticket['bus_ticket_id'] ?? null,
                ...($busPrices[(string) ($ticket['bus_ticket_id'] ?? '')] ?? []),
            ]);
        }

        $items = array_merge(
            $items,
            $this->ticketServices($detailed, $order, $currency, 'aeroexpress', 'aeroexpress_tickets', 'aeroexpress_ticket_id', 'aeroexpress_ticket_number', 'aeroexpress_ticket_totalcost', 'aeroexpress_ticket_status'),
        );

        $filesByService = [];
        foreach ($this->rows($detailed['service_files'] ?? []) as $file) {
            if (in_array($file['service_file_mark_to_delete'] ?? 0, [1, '1', true, 'Y', 'true'], true)) {
                continue;
            }
            $serviceId = (int) ($file['service_file_service_id'] ?? 0);
            $fileId = (int) ($file['service_file_id'] ?? 0);
            if ($serviceId <= 0 || $fileId <= 0) {
                continue;
            }
            $filesByService[$serviceId][$fileId] = array_intersect_key($file, array_flip([
                'service_file_id', 'service_file_service_id', 'service_file_date',
                'service_file_name', 'service_file_filename', 'service_file_type',
            ]));
            ksort($filesByService[$serviceId][$fileId]);
        }
        $output = [];
        foreach (array_filter($items) as $item) {
            $data = $item->data;
            $files = $filesByService[(int) ($data['service_id'] ?? 0)] ?? [];
            ksort($files);
            $data['trivio_service_files'] = array_values($files);
            $data['document_revision'] = hash('sha256', json_encode([
                $data['updated_at'] ?? $data['created_at'] ?? null, $data['trivio_service_files'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output[] = new TravelItem($item->provider, $item->externalId, $item->serviceType, $data);
        }
        return $output;
    }

    private function item(
        string $type,
        mixed $id,
        array $order,
        array $service,
        array $person,
        string $currency,
        array $overrides,
        ?string $externalType = null,
    ): ?TravelItem {
        if ($id === null || $id === '') {
            return null;
        }
        $passenger = $this->passenger($person);
        $serviceId = $service['service_id'] ?? $person['service_id'] ?? $service['arbitrary_service_id'] ?? null;
        $status = $overrides['status'] ?? $service['service_current_status'] ?? null;
        $orderId = $order['order_id'] ?? $person['avia_ticket_order_id'] ?? $person['train_ufs_ticket_order_id'] ?? null;
        $data = [
            'order_id' => $orderId,
            'service_id' => $serviceId,
            'ticket_number' => $overrides['ticket_number'] ?? null,
            'booking_number' => $overrides['booking_number'] ?? null,
            'service_url' => $overrides['service_url']
                ?? ServiceUrlExtractor::forProvider('trivio', $orderId, $person, $service, $order),
            'status' => $this->statusLabel($status),
            'status_code' => $status,
            'passenger' => trim(implode(' ', array_filter($passenger))),
            'passenger_first_name' => $passenger[1] ?? null,
            'passenger_last_name' => $passenger[0] ?? null,
            'passenger_middle_name' => $passenger[2] ?? null,
            'route' => $overrides['route'] ?? null,
            'departure_location' => $overrides['departure_location'] ?? null,
            'arrival_location' => $overrides['arrival_location'] ?? null,
            'departure_at' => $overrides['departure_at'] ?? null,
            'arrival_at' => $overrides['arrival_at'] ?? null,
            'check_in' => $overrides['check_in'] ?? null,
            'check_out' => $overrides['check_out'] ?? null,
            'amount' => $overrides['amount'] ?? null,
            'ticket_amount' => $overrides['ticket_amount'] ?? $overrides['amount'] ?? null,
            'service_fee' => $overrides['service_fee'] ?? null,
            'currency' => $currency,
            'company_name' => $order['order_company_name'] ?? null,
            'company_inn' => null,
            'hotel_name' => $overrides['hotel_name'] ?? null,
            'hotel_address' => $overrides['hotel_address'] ?? null,
            'room_name' => $overrides['room_name'] ?? null,
            'created_at' => $service['service_create_at'] ?? $service['service_date'] ?? $order['order_date'] ?? null,
            'updated_at' => $service['service_update_at'] ?? $order['order_update'] ?? null,
            'project' => null,
            'trivio_service_id' => (int) ($serviceId ?? 0),
            'trivio_ticket_id' => (int) ($overrides['trivio_ticket_id'] ?? 0),
            'raw_json' => json_encode($this->scrub(['order' => $order, 'service' => $service, 'item' => $person]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $description = $this->firstNonEmpty([
            $person['avia_ticket_description'] ?? null,
            $person['train_ufs_ticket_description'] ?? null,
            $service['description'] ?? null,
            $service['title'] ?? null,
            $overrides['route'] ?? null,
        ]);
        $isAdditional = $type === ServiceType::ADDITIONAL_SERVICE || AdditionalServiceFilter::matches(
            $type, $description, $service['title'] ?? null,
        );
        $serviceType = $isAdditional
            ? ServiceType::ADDITIONAL_SERVICE
            : ServiceType::normalize($type);
        if ($isAdditional) {
            $data['additional_service_name'] = $service['title'] ?? $description;
            $data['document_service_type'] = $type;
            $data['base_ticket_number'] = $this->baseTicketNumber($person + $service, $description);
        }

        return new TravelItem('trivio', ($externalType ?? $type) . ":{$id}", $serviceType, $data);
    }

    private function simpleServices(array $detailed, array $order, string $currency, string $type, string $key, string $idKey, array $map): array
    {
        $items = [];
        foreach ($this->rows($detailed[$key] ?? []) as $service) {
            $isAdditionalService = AdditionalServiceFilter::matches(
                $service['service_type'] ?? $type,
                $service['title'] ?? null,
                $service['name'] ?? null,
                $service['description'] ?? null,
                $service['service_name'] ?? null,
            );
            $overrides = [];
            foreach ($map as $target => $sources) {
                $values = array_values(array_filter(array_map(static fn (string $source): mixed => $service[$source] ?? null, $sources), static fn (mixed $value): bool => $value !== null && $value !== ''));
                $overrides[$target] = $target === 'route' && count($values) > 1 ? implode(' → ', $values) : ($values[0] ?? null);
            }
            $overrides['status'] = $service['service_current_status'] ?? null;
            if ($type === 'other') {
                $overrides['service_fee'] = ($this->money($service['arbitrary_service_fee'] ?? null) ?? 0.0)
                    + ($this->money($service['arbitrary_service_provider_fee'] ?? null) ?? 0.0);
                $overrides['ticket_amount'] = isset($overrides['amount'])
                    ? (float) $overrides['amount'] - $overrides['service_fee'] : null;
            }
            $itemType = $isAdditionalService ? ServiceType::ADDITIONAL_SERVICE : $type;
            $item = $this->item(
                $itemType,
                $service[$idKey] ?? $service['service_id'] ?? null,
                $order,
                $service,
                $service,
                $currency,
                $overrides,
                $type,
            );
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function ticketServices(array $detailed, array $order, string $currency, string $type, string $key, string $idKey, string $numberKey, string $amountKey, string $statusKey): array
    {
        $items = [];
        foreach ($this->rows($detailed[$key] ?? []) as $ticket) {
            $item = $this->item($type, $ticket[$idKey] ?? null, $order, [], $ticket, $currency, [
                'ticket_number' => $ticket[$numberKey] ?? null,
                'amount' => $ticket[$amountKey] ?? null,
                'status' => $ticket[$statusKey] ?? null,
                'trivio_ticket_id' => $ticket[$idKey] ?? null,
                'departure_at' => $ticket['aeroexpress_ticket_trip_date'] ?? null,
                'route' => $ticket['aeroexpress_ticket_description'] ?? $ticket['bus_ticket_description'] ?? null,
            ]);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function ticketPrices(mixed $rows, array $services, string $ticketPrefix, string $servicePrefix): array
    {
        $groups = [];
        foreach ($this->rows($rows) as $ticket) {
            if (!isset($ticket[$ticketPrefix . 'id'])) {
                continue;
            }
            $service = $services[(string) ($ticket['service_id'] ?? $ticket[$ticketPrefix . 'service_id'] ?? '')] ?? [];
            $key = (string) ($service['service_id'] ?? $service[$servicePrefix . 'id'] ?? 'ticket:' . $ticket[$ticketPrefix . 'id']);
            $groups[$key]['service'] = $service;
            $groups[$key]['tickets'][] = $ticket;
        }
        $prices = [];
        foreach ($groups as $group) {
            $prices += TrivioPricing::tickets($group['tickets'], $group['service'], $ticketPrefix, $servicePrefix);
        }
        return $prices;
    }

    private function baseTicketNumber(array $data, mixed $description): ?string
    {
        $explicit = $this->firstNonEmpty([
            $data['base_ticket_number'] ?? null,
            $data['arbitrary_service_base_ticket_number'] ?? null,
            $data['avia_service_arbitrary_base_ticket_id'] ?? null,
        ]);
        if ($explicit !== null) {
            return (string) $explicit;
        }
        if (preg_match('/(?:Авиабилет|Ж[\/Дд\s-]*билет|Билет)\s*;\s*([0-9][0-9 -]{6,}[0-9])/iu', (string) $description, $match) === 1) {
            return (string) preg_replace('/\s+/', '', $match[1]);
        }
        return null;
    }

    private function passenger(array $row): array
    {
        return [
            (string) ($row['passenger_last_name'] ?? $row['user_last_name'] ?? $row['hotel_roomer_last_name'] ?? $row['hotel_roomer_user_last_name'] ?? ''),
            (string) ($row['passenger_first_name'] ?? $row['user_first_name'] ?? $row['hotel_roomer_first_name'] ?? $row['hotel_roomer_user_first_name'] ?? ''),
            (string) ($row['passenger_middle_name'] ?? $row['user_middle_name'] ?? ''),
        ];
    }

    private function statusLabel(mixed $status): string
    {
        return match ((string) $status) {
            '0' => 'Новый', '1' => 'Резерв', '2' => 'Оформлено', '3' => 'Аннулировано',
            '4' => 'Запрос на отмену', '5' => 'Отменено', '6' => 'Оформляется',
            '7' => 'На проверке', '8' => 'Обмен', '9' => 'Войдировано',
            '10' => 'Истёк резерв', '11' => 'Отменяется', '12' => 'Резервируется',
            '13' => 'Удалено',
            default => (string) $status,
        };
    }

    private function money(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    /** @param list<mixed> $values */
    private function firstMoney(array $values): ?float
    {
        $zero = null;
        foreach ($values as $value) {
            $amount = $this->money($value);
            if ($amount !== null && $amount != 0.0) {
                return abs($amount);
            }
            if ($amount === 0.0) {
                $zero = 0.0;
            }
        }
        return $zero;
    }

    /** @param list<mixed> $values */
    private function sumMoney(array $values): ?float
    {
        $total = 0.0;
        $found = false;
        foreach ($values as $value) {
            $amount = $this->money($value);
            if ($amount !== null) {
                $total += abs($amount);
                $found = true;
            }
        }
        return $found ? $total : null;
    }

    private function firstSegment(array $service, string $field, bool $last = false): mixed
    {
        $segments = [];
        foreach ($this->rows($service['flight_info'] ?? []) as $flight) {
            foreach ($this->rows($flight['directions'] ?? []) as $direction) {
                $segments = array_merge($segments, $this->rows($direction['segments'] ?? []));
            }
        }
        if ($segments === []) {
            return null;
        }
        $segment = $last ? $segments[array_key_last($segments)] : $segments[0];
        return $segment[$field] ?? null;
    }

    private function indexBy(mixed $rows, array $keys): array
    {
        $index = [];
        foreach ($this->rows($rows) as $row) {
            foreach ($keys as $key) {
                if (isset($row[$key])) {
                    $index[(string) $row[$key]] = $row;
                }
            }
        }
        return $index;
    }

    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (!array_is_list($value)) {
            return [$value];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function firstNonEmpty(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && (!is_string($value) || trim($value) !== '')) {
                return $value;
            }
        }

        return null;
    }

    /** @param list<mixed> $values */
    private function mostPreciseDateTime(array $values): mixed
    {
        $fallback = null;
        foreach ($values as $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            $fallback ??= $value;
            if (!is_string($value)) {
                continue;
            }
            $candidate = trim($value);
            // A time explicitly present in the source remains authoritative,
            // including a legitimate midnight departure at 00:00.
            if (preg_match('/(?:T|\s)\d{1,2}:\d{2}(?::\d{2})?(?:\s*[+-]\d{2}:?\d{2}|Z)?$/i', $candidate) === 1) {
                return $value;
            }
        }

        return $fallback;
    }

    private function routeLabel(mixed $departure, mixed $arrival): ?string
    {
        $departure = trim((string) $departure);
        $arrival = trim((string) $arrival);
        if ($departure === '' && $arrival === '') {
            return null;
        }
        if ($departure === '' || $arrival === '') {
            return $departure !== '' ? $departure : $arrival;
        }

        return "{$departure} → {$arrival}";
    }

    private function routeEndpoints(mixed $route): array
    {
        $route = trim((string) $route);
        if ($route === '') {
            return [null, null];
        }
        $parts = preg_split('/\s*(?:→|—|–|->)\s*|\s+-\s+/u', $route, 2);
        if (!is_array($parts) || count($parts) !== 2) {
            return [null, null];
        }

        $departure = trim($parts[0]);
        $arrival = trim($parts[1]);
        return [
            $departure !== '' ? $departure : null,
            $arrival !== '' ? $arrival : null,
        ];
    }

    private function scrub(array $value): array
    {
        $sensitive = ['password', 'document_no', 'passenger_doc_num', 'phone', 'email', 'document_number'];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $value[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $value[$key] = $this->scrub($item);
            }
        }
        return $value;
    }

    private function analyticsLabel(mixed $analytics): ?string
    {
        if (!is_array($analytics)) {
            return null;
        }
        $rows = isset($analytics['analyticvaluedto']) ? $analytics['analyticvaluedto'] : $analytics;
        if (is_array($rows) && isset($rows['analytic_name'])) {
            $rows = [$rows];
        }
        if (!is_array($rows)) {
            return null;
        }
        $labels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['analytic_name'] ?? ''));
            $value = trim((string) ($row['analytic_value'] ?? ''));
            if ($value !== '') {
                $labels[] = $name === '' ? $value : "{$name}: {$value}";
            }
        }

        return $labels === [] ? null : implode('; ', $labels);
    }
}
