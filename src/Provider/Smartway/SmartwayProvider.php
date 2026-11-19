<?php

declare(strict_types=1);

namespace App\Provider\Smartway;

use App\Domain\ServiceType;
use App\Domain\TravelDocument;
use App\Domain\TravelItem;
use App\Provider\AdditionalServiceFilter;
use App\Provider\TravelDocumentProviderInterface;
use App\Provider\ServiceUrlExtractor;
use App\Provider\TravelProviderInterface;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SmartwayProvider implements TravelProviderInterface, TravelDocumentProviderInterface
{
    private const ENDPOINT = 'https://api.smartway.today/v2';
    private const API_TIMEZONE = 'Europe/Moscow';
    private const MAX_DOCUMENT_BYTES = 15 * 1024 * 1024;
    private HttpClientInterface $http;

    public function __construct(
        private readonly array $credentials,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 30,
            'max_duration' => 90,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Bitrix24-Travel-Sync/1.0',
            ],
        ]);
    }

    public function code(): string
    {
        return 'smartway';
    }

    public function testConnection(): void
    {
        $result = $this->rpc('ping.ping', ['query' => []]);
        if ($result !== "I'm alive") {
            throw new RuntimeException('Smartway не подтвердил соединение методом ping.ping.');
        }
    }

    public function fetch(?string $cursor, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($cursor === null || !ctype_digit($cursor)) {
            return $this->fetchInitialRange($from, $to);
        }

        $version = max(0, (int) $cursor);
        $items = [];
        $nextCursor = (string) $version;
        for ($page = 0; $page < 100; $page++) {
            $result = $this->arrayResult(
                $this->rpc('trip.get_after_change', ['query' => $version, 'extended' => true]),
                'trip.get_after_change',
            );
            $trips = is_array($result['trips'] ?? null) ? $result['trips'] : [];
            $items = [...$items, ...$this->normalizeTrips($trips)];

            $receivedCursor = (string) ($result['last_version_id'] ?? $version);
            $nextCursor = $receivedCursor;
            if (count($trips) < 1000 || (int) $receivedCursor <= $version) {
                break;
            }
            $version = (int) $receivedCursor;
        }

        return ['items' => $items, 'nextCursor' => $nextCursor];
    }

    public function ticketDocument(TravelItem $item): ?TravelDocument
    {
        $itemId = (int) ($item->data['service_id'] ?? 0);
        if ($itemId <= 0) {
            return null;
        }

        $result = $this->arrayResult(
            $this->rpc('trip.item_voucher', ['query' => $itemId]),
            'trip.item_voucher',
        );
        $encoded = preg_replace('/\s+/', '', (string) ($result['content'] ?? ''));
        if ($encoded === '') {
            return null;
        }
        $content = base64_decode($encoded, true);
        if ($content === false || $content === '') {
            throw new RuntimeException('Smartway вернул некорректный base64 ваучера.');
        }
        if (strlen($content) > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('PDF-ваучер Smartway превышает лимит 15 МБ.');
        }
        if (!str_starts_with($content, '%PDF-')) {
            throw new RuntimeException('Smartway вернул файл ваучера не в формате PDF.');
        }

        return new TravelDocument(
            sprintf('smartway-%s-%d.pdf', $item->serviceType, $itemId),
            $content,
            'application/pdf',
        );
    }

    private function fetchInitialRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $items = [];
        $latestVersion = 0;
        // Smartway accepts ISO-like values without a timezone offset and treats
        // them as Moscow local time. Convert UTC scheduler boundaries before
        // removing the offset; otherwise fresh bookings can be missed for up
        // to three hours.
        $timezone = new \DateTimeZone(self::API_TIMEZONE);
        $rangeTo = $to->setTimezone($timezone);
        $start = $from->setTimezone($timezone)->setTime(0, 0);
        while ($start <= $rangeTo) {
            $end = $start->modify('+13 days')->setTime(23, 59, 59);
            if ($end > $rangeTo) {
                $end = $rangeTo;
            }
            try {
                $result = $this->arrayResult($this->rpc('trip.get_by_range', [
                    'query' => [
                        'start_date' => $start->format('Y-m-d\TH:i:s'),
                        'end_date' => $end->format('Y-m-d\TH:i:s'),
                        'extended' => true,
                    ],
                ]), 'trip.get_by_range');
            } catch (RuntimeException $exception) {
                if (!$this->isEmptyRangeResponse($exception)) {
                    throw $exception;
                }
                // Smartway фактически возвращает JSON-RPC -32000/status 204,
                // когда в корректном диапазоне нет поездок, вместо trips: null.
                $result = ['trips' => null];
            }
            $trips = is_array($result['trips'] ?? null) ? $result['trips'] : [];
            $items = [...$items, ...$this->normalizeTrips($trips)];

            if ((int) ($result['last_version_id'] ?? 0) > $latestVersion) {
                $latestVersion = (int) $result['last_version_id'];
            }
            $start = $end->modify('+1 second');
        }

        // Если весь период пуст, сохраняем время проверки. Следующий плановый
        // запуск снова запросит только новый временной диапазон, а не всю
        // историю через trip.get_after_change с версией 0.
        $nextCursor = $latestVersion > 0
            ? (string) $latestVersion
            : $to->format(DATE_ATOM);

        return ['items' => $items, 'nextCursor' => $nextCursor];
    }

    private function isEmptyRangeResponse(RuntimeException $exception): bool
    {
        return $exception->getCode() === -32000
            && str_contains(strtolower($exception->getMessage()), 'status code = 204');
    }

    private function rpc(string $method, array $params): mixed
    {
        $params = ['authorization' => [
            'username' => (string) ($this->credentials['username'] ?? ''),
            'password' => (string) ($this->credentials['password'] ?? ''),
        ]] + $params;
        $payload = [
            'jsonrpc' => '2.0',
            'id' => bin2hex(random_bytes(8)),
            'method' => $method,
            'params' => $params,
        ];

        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->http->request('POST', self::ENDPOINT, ['json' => $payload]);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
            } catch (\Throwable $exception) {
                $lastError = $exception;
                if ($attempt < 3) {
                    usleep(200_000 * $attempt);
                    continue;
                }
                break;
            }

            if ($status === 429 || $status >= 500) {
                $lastError = new RuntimeException("HTTP {$status}", $status);
                if ($attempt < 3) {
                    usleep(200_000 * $attempt);
                    continue;
                }
                break;
            }
            if ($status >= 400) {
                throw new RuntimeException("Ошибка Smartway {$method}: HTTP {$status}.", $status);
            }

            try {
                $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException(
                    "Ошибка Smartway {$method}: API вернул некорректный JSON.",
                    previous: $exception,
                );
            }
            if (!is_array($decoded)) {
                throw new RuntimeException("Ошибка Smartway {$method}: API вернул некорректный ответ.");
            }
            if (isset($decoded['error'])) {
                $error = is_array($decoded['error']) ? $decoded['error'] : [];
                $message = trim((string) ($error['message'] ?? 'JSON-RPC error'));
                $code = (int) ($error['code'] ?? 0);
                throw new RuntimeException(
                    "Ошибка Smartway {$method}: {$message}" . ($code !== 0 ? " (код {$code})" : ''),
                    $code,
                );
            }
            if (!array_key_exists('result', $decoded)) {
                throw new RuntimeException("Ошибка Smartway {$method}: в ответе отсутствует result.");
            }

            return $decoded['result'];
        }

        throw new RuntimeException(
            "Ошибка Smartway {$method}: " . ($lastError?->getMessage() ?? 'сервер не ответил'),
            (int) ($lastError?->getCode() ?? 0),
            $lastError,
        );
    }

    private function arrayResult(mixed $result, string $method): array
    {
        if (!is_array($result)) {
            throw new RuntimeException("Ошибка Smartway {$method}: result должен быть объектом.");
        }

        return $result;
    }

    private function normalizeTrips(array $trips): array
    {
        $items = [];
        foreach ($trips as $trip) {
            if (!is_array($trip)) {
                continue;
            }
            foreach (is_array($trip['items'] ?? null) ? $trip['items'] : [] as $item) {
                if (is_array($item)) {
                    $items[] = $this->normalize($trip, $item);
                }
            }
        }

        return $items;
    }

    private function normalize(array $trip, array $item): TravelItem
    {
        $identityType = ServiceType::normalize((string) ($item['service_type'] ?? ''));
        $type = AdditionalServiceFilter::matches(
            $item['service_type'] ?? $item['type'] ?? null,
            $item['name'] ?? null,
            $item['title'] ?? null,
            $item['description'] ?? null,
            $item['service_name'] ?? null,
        ) ? ServiceType::ADDITIONAL_SERVICE : $identityType;
        $id = (string) ($item['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Smartway вернул услугу без id.');
        }
        $travellers = is_array($item['travellers'] ?? null) ? $item['travellers'] : [];
        $traveller = is_array($travellers[0] ?? null) ? $travellers[0] : [];
        $passengers = [];
        foreach ($travellers as $person) {
            if (!is_array($person)) {
                continue;
            }
            $name = trim(implode(' ', array_filter([
                $person['surname'] ?? '', $person['name'] ?? '', $person['patronymic'] ?? '',
            ])));
            if ($name !== '') {
                $passengers[] = $name;
            }
        }
        $company = is_array($item['company'] ?? null) ? $item['company'] : [];
        [$route, $departure, $arrival, $hotel, $address, $departureLocation, $arrivalLocation] = $this->routeFields($item);
        $info = is_array($item['info'] ?? null) ? $item['info'] : [];

        $tripId = $trip['id'] ?? null;

        return new TravelItem('smartway', "{$identityType}:{$id}", $type, [
            'order_id' => $tripId,
            'service_id' => $id,
            'ticket_number' => $item['number'] ?? $info['ticket_number'] ?? null,
            'booking_number' => null,
            'service_url' => ServiceUrlExtractor::forProvider('smartway', $tripId, $item, $trip),
            'status' => $item['status'] ?? $trip['status'] ?? null,
            'status_code' => $item['status'] ?? null,
            'passenger' => implode(', ', $passengers),
            'passenger_first_name' => $traveller['name'] ?? null,
            'passenger_last_name' => $traveller['surname'] ?? null,
            'passenger_middle_name' => $traveller['patronymic'] ?? null,
            'route' => $route,
            'departure_location' => $type !== ServiceType::ACCOMMODATION ? $departureLocation : null,
            'arrival_location' => $type !== ServiceType::ACCOMMODATION ? $arrivalLocation : null,
            'departure_at' => $type !== ServiceType::ACCOMMODATION ? ($departure ?? $item['checkin_date'] ?? null) : null,
            'arrival_at' => $type !== ServiceType::ACCOMMODATION ? ($arrival ?? $item['checkout_date'] ?? null) : null,
            'check_in' => $type === ServiceType::ACCOMMODATION ? ($item['checkin_date'] ?? $departure) : null,
            'check_out' => $type === ServiceType::ACCOMMODATION ? ($item['checkout_date'] ?? $arrival) : null,
            'amount' => $item['amount'] ?? null,
            'currency' => 'RUB',
            'company_name' => $company['name'] ?? null,
            'company_inn' => $company['inn'] ?? null,
            'hotel_name' => $hotel,
            'hotel_address' => $address,
            'room_name' => $info['room_category'] ?? null,
            'created_at' => $item['book_date'] ?? $trip['book_date'] ?? null,
            'updated_at' => $item['last_change_date'] ?? $trip['last_change_date'] ?? null,
            'project' => $item['project'] ?? null,
            'raw_json' => json_encode(
                $this->scrub(['trip' => $trip, 'item' => $item]),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        ]);
    }

    private function routeFields(array $item): array
    {
        $info = $item['info'] ?? [];
        if (!is_array($info)) {
            return [null, null, null, null, null, null, null];
        }

        $infoEntries = array_is_list($info) ? $info : [$info];
        $segments = [];
        foreach ($infoEntries as $entry) {
            if (is_array($entry) && is_array($entry['segments'] ?? null)) {
                $segments = [...$segments, ...$entry['segments']];
            }
        }
        if ($segments !== []) {
            $first = is_array($segments[0] ?? null) ? $segments[0] : [];
            $last = is_array($segments[array_key_last($segments)] ?? null)
                ? $segments[array_key_last($segments)]
                : $first;
            $from = $this->placeName($first['place_depart'] ?? null);
            $to = $this->placeName($last['place_arrive'] ?? null);
            return [
                $this->routeName($from, $to),
                $first['date_depart'] ?? null,
                $last['date_arrive'] ?? null,
                null,
                null,
                $from,
                $to,
            ];
        }

        $data = is_array($infoEntries[0] ?? null) ? $infoEntries[0] : [];
        $from = $data['station_depart'] ?? $data['departure'] ?? $this->placeName($data['place_depart'] ?? null);
        $to = $data['station_arrive'] ?? $data['destination'] ?? $this->placeName($data['place_arrive'] ?? null);
        $hotel = $data['hotel_name'] ?? null;
        if ($hotel !== null && $from === null && $to === null) {
            $from = $data['city'] ?? null;
            $to = $hotel;
        }

        return [
            $this->routeName($from, $to),
            null,
            null,
            $hotel,
            $data['hotel_address'] ?? $data['address'] ?? null,
            $from,
            $to,
        ];
    }

    private function placeName(mixed $place): ?string
    {
        if (!is_array($place)) {
            return is_string($place) && trim($place) !== '' ? trim($place) : null;
        }
        foreach (['city', 'place', 'address', 'name', 'code'] as $key) {
            $value = trim((string) ($place[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function routeName(mixed $from, mixed $to): ?string
    {
        $from = trim((string) $from);
        $to = trim((string) $to);
        if ($from === '' && $to === '') {
            return null;
        }
        if ($from === '' || $to === '') {
            return $from !== '' ? $from : $to;
        }

        return "{$from} → {$to}";
    }

    private function scrub(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['password', 'documents', 'phone', 'email', 'birthday'], true)) {
                $value[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $value[$key] = $this->scrub($item);
            }
        }

        return $value;
    }
}
