<?php

declare(strict_types=1);

namespace App\Provider\Aviasales;

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

final class AviasalesProvider implements TravelProviderInterface, TravelDocumentProviderInterface
{
    private const MAX_DOCUMENT_BYTES = 15 * 1024 * 1024;
    private const ENDPOINT = 'https://b2b.aviasales.ru/api/orders';
    private const COMPANIES_ENDPOINT = 'https://b2b.aviasales.ru/api/companies';
    private HttpClientInterface $http;
    private ?array $companyDetails = null;
    private bool $companyDetailsLoaded = false;

    public function __construct(
        private readonly array $credentials,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create([
            'timeout' => 30,
            'max_duration' => 60,
        ]);
    }

    public function code(): string
    {
        return 'aviasales';
    }

    public function testConnection(): void
    {
        try {
            $this->request(['page_size' => 1]);
        } catch (RuntimeException $ordersError) {
            if (!in_array($ordersError->getCode(), [401, 403], true)) {
                throw $ordersError;
            }

            $companiesResponse = $this->request([], self::COMPANIES_ENDPOINT);
            $companies = array_is_list($companiesResponse)
                ? $companiesResponse
                : (is_array($companiesResponse['results'] ?? null) ? $companiesResponse['results'] : []);
            $companyId = (string) ($this->credentials['company_id'] ?? '');
            foreach ($companies as $company) {
                if (is_array($company) && hash_equals($companyId, (string) ($company['id'] ?? ''))) {
                    throw $ordersError;
                }
            }

            throw new RuntimeException(
                'Aviasales принял API-ключ, но указанный UUID организации отсутствует в GET /api/companies.',
                $ordersError->getCode(),
                $ordersError,
            );
        }
    }

    public function fetch(?string $cursor, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $page = 1;
        $items = [];
        do {
            $params = [
                'created_at_from' => $from->format('Y-m-d'),
                'created_at_to' => $to->format('Y-m-d'),
                'ordering' => 'created_at',
                'page' => $page,
                'page_size' => 100,
            ];
            $result = $this->request($params);
            $orders = is_array($result['results'] ?? null) ? $result['results'] : [];
            $company = $orders !== [] ? $this->companyDetails() : [];
            foreach ($orders as $order) {
                if (is_array($order)) {
                    $item = $this->normalize($order, $company);
                    if ($this->needsOrderDetails($item)) {
                        $orderId = (string) ($item->data['order_id'] ?? '');
                        if ($orderId !== '') {
                            $details = $this->request([], self::ENDPOINT . '/' . rawurlencode($orderId));
                            $merged = array_replace_recursive($order, $details);
                            if (array_key_exists('itineraries', $details)) {
                                $merged['itineraries'] = $details['itineraries'];
                            }
                            $item = $this->normalize($merged, $company);
                        }
                    }
                    $items[] = $item;
                }
            }
            $page++;
            $next = $result['next'] ?? null;
        } while (is_string($next) && $next !== '' && $page <= 100);

        return ['items' => $items, 'nextCursor' => $to->format(DATE_ATOM)];
    }

    public function ticketDocument(TravelItem $item): ?TravelDocument
    {
        if (!in_array($item->serviceType, [ServiceType::AIR, ServiceType::RAIL], true)) {
            return null;
        }
        $orderId = trim((string) ($item->data['order_id'] ?? ''));
        if ($orderId === '') {
            throw new RuntimeException('Невозможно получить билет Aviasales: у заказа отсутствует UUID.');
        }

        $urls = $this->itineraryUrls($item->data['itineraries'] ?? null);
        if ($urls === [] && empty($item->data['itineraries_loaded'])) {
            $order = $this->request([], self::ENDPOINT . '/' . rawurlencode($orderId));
            $urls = $this->itineraryUrls($order['itineraries'] ?? null);
        }
        if ($urls === []) {
            return null;
        }

        $lastError = null;
        foreach ($urls as $url) {
            try {
                return $this->downloadDocument($url, $orderId);
            } catch (\Throwable $exception) {
                $lastError = $exception;
            }
        }

        throw new RuntimeException(
            'Не удалось скачать ни одну маршрутную квитанцию Aviasales: '
                . ($lastError?->getMessage() ?? 'неизвестная ошибка'),
            (int) ($lastError?->getCode() ?? 0),
            $lastError,
        );
    }

    private function request(
        array $query,
        string $endpoint = self::ENDPOINT,
        string $method = 'GET',
        ?array $json = null,
    ): array
    {
        $isCompaniesEndpoint = $endpoint === self::COMPANIES_ENDPOINT
            || str_starts_with($endpoint, self::COMPANIES_ENDPOINT . '/');
        $operation = $isCompaniesEndpoint
            ? ($endpoint === self::COMPANIES_ENDPOINT ? 'GET /api/companies' : 'GET /api/companies/{id}')
            : (str_starts_with($endpoint, self::ENDPOINT . '/')
                ? 'GET /api/orders/{id}'
                : 'GET /api/orders');
        if ($endpoint !== self::COMPANIES_ENDPOINT) {
            $companyId = (string) ($this->credentials['company_id'] ?? '');
            $query['current_company'] = $companyId;
            if ($endpoint === self::ENDPOINT) {
                $query['company'] = $companyId;
            }
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $options = [
                    'query' => $query,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Token ' . $this->apiKey(),
                        'User-Agent' => 'Bitrix24-Travel-Sync/1.0',
                    ],
                ];
                if ($json !== null) {
                    $options['json'] = $json;
                }
                $response = $this->http->request($method, $endpoint, $options);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
                $decoded = json_decode($body, true);
                if ($status >= 500 || $status === 429) {
                    throw new RuntimeException("{$operation} — HTTP {$status}: " . $this->apiError($decoded), $status);
                }
                if ($status >= 400) {
                    throw new RuntimeException("{$operation} — HTTP {$status}: " . $this->apiError($decoded), $status);
                }
                if (!is_array($decoded)) {
                    throw new RuntimeException("{$operation} — HTTP {$status}: Aviasales вернул ответ не в формате JSON.");
                }
                return $decoded;
            } catch (\Throwable $exception) {
                $lastError = $exception;
                $status = (int) $exception->getCode();
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    throw new RuntimeException(
                        'Ошибка Aviasales B2B: ' . $exception->getMessage(),
                        $status,
                        $exception,
                    );
                }
                if ($attempt < 3) {
                    usleep(200_000 * $attempt);
                }
            }
        }
        throw new RuntimeException(
            'Ошибка Aviasales B2B: ' . ($lastError?->getMessage() ?? 'неизвестная ошибка'),
            (int) ($lastError?->getCode() ?? 0),
            $lastError,
        );
    }

    private function downloadDocument(string $url, string $orderId, int $redirectDepth = 0): TravelDocument
    {
        if ($redirectDepth > 2) {
            throw new RuntimeException('Aviasales вернул слишком длинную цепочку ссылок на билет.');
        }
        if (str_starts_with($url, '/')) {
            $url = 'https://b2b.aviasales.ru' . $url;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new RuntimeException('Aviasales вернул небезопасную ссылку на файл билета.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ) {
            throw new RuntimeException('Aviasales вернул ссылку на файл во внутренней сети.');
        }

        $options = [
            'headers' => [
                'Accept' => '*/*',
                'User-Agent' => 'Bitrix24-Travel-Sync/1.0',
            ],
            'max_redirects' => 3,
        ];
        if ($host === 'b2b.aviasales.ru') {
            $options['headers']['Authorization'] = 'Token ' . $this->apiKey();
            $options['query'] = ['current_company' => (string) ($this->credentials['company_id'] ?? '')];
        }

        $response = $this->http->request('GET', $url, $options);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new RuntimeException("Не удалось скачать билет Aviasales: HTTP {$status}.");
        }
        $headers = $response->getHeaders(false);
        $declaredSize = (int) ($headers['content-length'][0] ?? 0);
        if ($declaredSize > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('Файл билета Aviasales превышает лимит 15 МБ.');
        }
        $content = $response->getContent(false);
        if ($content === '' || strlen($content) > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('Файл билета Aviasales пустой или превышает лимит 15 МБ.');
        }

        $json = json_decode($content, true);
        $signedUrl = is_array($json) && is_string($json['url'] ?? null)
            ? trim($json['url'])
            : '';
        if ($signedUrl !== '') {
            return $this->downloadDocument($signedUrl, $orderId, $redirectDepth + 1);
        }

        [$contentType, $extension] = $this->detectDocumentType($content);
        $filename = $this->documentFilename($headers, $orderId, $extension);

        return new TravelDocument($filename, $content, $contentType);
    }

    /** @return array{0: string, 1: string} */
    private function detectDocumentType(string $content): array
    {
        if (str_starts_with($content, '%PDF-')) {
            return ['application/pdf', 'pdf'];
        }
        if (str_starts_with($content, "PK\x03\x04")) {
            return ['application/zip', 'zip'];
        }
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
            return ['image/png', 'png'];
        }
        if (str_starts_with($content, "\xff\xd8\xff")) {
            return ['image/jpeg', 'jpg'];
        }

        throw new RuntimeException('Aviasales вернул файл билета неподдерживаемого типа.');
    }

    private function documentFilename(array $headers, string $orderId, string $extension): string
    {
        $disposition = (string) ($headers['content-disposition'][0] ?? '');
        $filename = '';
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches)) {
            $filename = rawurldecode(trim($matches[1]));
        }
        $filename = preg_replace('/[^a-zA-Z0-9._-]+/u', '-', $filename) ?? '';
        $filename = trim($filename, '.-_');
        if ($filename === '') {
            $safeOrderId = preg_replace('/[^a-zA-Z0-9-]+/', '-', $orderId) ?: 'order';
            $filename = "aviasales-ticket-{$safeOrderId}.{$extension}";
        } elseif (!str_ends_with(strtolower($filename), ".{$extension}")) {
            $filename .= ".{$extension}";
        }

        return substr($filename, 0, 180);
    }

    private function apiError(mixed $decoded): string
    {
        if (is_array($decoded)) {
            foreach (['detail', 'message', 'error'] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                    return substr(trim((string) $decoded[$key]), 0, 500);
                }
            }
        }

        return 'сервер не передал описание ошибки';
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) ($this->credentials['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('Не указан API-ключ Aviasales.');
        }

        return $apiKey;
    }

    private function normalize(array $order, array $company = []): TravelItem
    {
        $ticket = is_array($order['ticket'] ?? null) ? $order['ticket'] : [];
        $user = is_array($order['user'] ?? null) ? $order['user'] : [];
        $traveler = $this->traveler($order, $ticket, $user);
        $first = $traveler['first'];
        $last = $traveler['last'];
        $middle = $traveler['middle'];
        $buyer = trim(implode(' ', array_filter([
            (string) ($user['last_name'] ?? ''),
            (string) ($user['first_name'] ?? ''),
            (string) ($user['middle_name'] ?? ''),
        ])));
        $identityType = ServiceType::normalize((string) ($order['type'] ?? ''));
        $type = AdditionalServiceFilter::matches(
            $order['type'] ?? $order['service_type'] ?? null,
            $order['name'] ?? null,
            $order['title'] ?? null,
            $order['description'] ?? null,
            $ticket['name'] ?? null,
        ) ? ServiceType::ADDITIONAL_SERVICE : $identityType;
        $id = (string) ($order['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Aviasales вернул заказ без id.');
        }
        $identifierSources = [
            $ticket,
            is_array($order['parameters'] ?? null) ? $order['parameters'] : [],
            is_array($order['additionals'] ?? null) ? $order['additionals'] : [],
        ];
        $ticketNumbers = $this->identifiers($identifierSources, [
            'ticket_number', 'ticket_no', 'eticket_number', 'e_ticket_number',
            'blank_number', 'avia_ticket_number', 'rail_ticket_number',
        ], true);
        if ($ticketNumbers === [] && isset($ticket['number']) && is_scalar($ticket['number'])) {
            $ticketNumbers[] = trim((string) $ticket['number']);
        }
        if ($ticketNumbers === []) {
            $ticketNumbers = $this->identifiers($identifierSources, ['ticket_id']);
        }
        $bookingSources = [$order, ...$identifierSources];
        $bookingNumbers = $this->identifiers($bookingSources, [
            'booking_number', 'booking_code', 'reservation_number', 'reservation_code',
            'pnr', 'locator', 'record_locator',
        ]);
        if ($bookingNumbers === []) {
            $bookingNumbers = $this->identifiers($bookingSources, ['booking_id', 'proposal_id', 'book_hash']);
        }
        $departureAt = $this->firstValue($order, [
            'ticket.departure_at', 'ticket.departure_date_time', 'ticket.departure_utc_timestamp',
            'ticket.departure_timestamp', 'ticket.ride_segment_detail.datetime_start',
            'ticket.segments.0.train.items.departure_date_time',
            'ticket.segments.0.train.items.0.departure_date_time', 'ticket.dates.from',
            'ticket.proposal.stayDates.arrivalDateTime', 'ticket.check_in', 'date_from',
        ]);
        $arrivalAt = $this->firstValue($order, [
            'ticket.arrival_at', 'ticket.arrival_date_time', 'ticket.arrival_utc_timestamp',
            'ticket.arrival_timestamp', 'ticket.ride_segment_detail.datetime_end',
            'ticket.segments.0.train.items.arrival_date_time',
            'ticket.segments.0.train.items.0.arrival_date_time', 'ticket.dates.to',
            'ticket.proposal.stayDates.departureDateTime', 'ticket.check_out', 'date_to',
        ]);
        $departure = $this->firstValue($order, [
            'internal.search.segments.0.from.name',
            'ticket.segments.0.from.name', 'ticket.segments.0.departure.name',
            'ticket.segments.0.departure.city.name', 'ticket.departure.name',
            'ticket.ride_segment_detail.station_start.city', 'ticket.ride_segment_detail.station_start.name',
            'ticket.segments.0.train.items.passenger_departure_station_name',
            'ticket.segments.0.train.items.departure_station_name',
            'ticket.segments.0.train.items.from',
            'ticket.segments.0.train.items.0.departure_station_name',
            'ticket.segments.0.train.items.0.from',
            'ticket.departure_city', 'ticket.departure',
        ]);
        $arrival = $this->firstValue($order, [
            'internal.search.segments.0.to.name',
            'ticket.segments.0.to.name', 'ticket.segments.0.arrival.name',
            'ticket.segments.0.arrival.city.name', 'ticket.arrival.name',
            'ticket.ride_segment_detail.station_end.city', 'ticket.ride_segment_detail.station_end.name',
            'ticket.segments.0.train.items.passenger_arrival_station_name',
            'ticket.segments.0.train.items.arrival_station_name',
            'ticket.segments.0.train.items.to',
            'ticket.segments.0.train.items.0.arrival_station_name',
            'ticket.segments.0.train.items.0.to',
            'ticket.arrival_city', 'ticket.arrival',
        ]);
        $route = $this->firstValue($order, ['ticket.route', 'ticket.itinerary', 'ticket.ride_segment_detail.route_name']);
        $routeDeparture = $identityType === ServiceType::RAIL
            ? $this->cleanRailStationName($departure)
            : $departure;
        $routeArrival = $identityType === ServiceType::RAIL
            ? $this->cleanRailStationName($arrival)
            : $arrival;
        if ($route === null && ($departure !== null || $arrival !== null)) {
            $route = $this->routeLabel($routeDeparture, $routeArrival);
        } elseif ($this->isIataOnlyRoute((string) $route)
            && ($departure !== null || $arrival !== null)
            && (!$this->isIataCode((string) $departure) || !$this->isIataCode((string) $arrival))
        ) {
            $route = $this->routeLabel($departure, $arrival);
        }
        if ($identityType === ServiceType::RAIL && $route !== null) {
            $route = $this->cleanRailRoute((string) $route);
        }
        $hotelName = $type === ServiceType::ACCOMMODATION
            ? $this->firstValue($order, ['ticket.hotel_name', 'ticket.name'])
            : null;
        $hotelAddress = $type === ServiceType::ACCOMMODATION
            ? $this->firstValue($order, ['ticket.hotel_address', 'ticket.address'])
            : null;
        $roomName = $type === ServiceType::ACCOMMODATION
            ? $this->firstValue($order, ['ticket.room_name', 'ticket.proposal.name', 'ticket.proposal.roomType.name', 'ticket.proposal.roomType.id'])
            : null;

        return new TravelItem('aviasales', "{$identityType}:{$id}", $type, [
            'order_id' => $id,
            'short_order_id' => $this->shortOrderId($id),
            'service_id' => $id,
            'itineraries' => $this->itineraryUrls($order['itineraries'] ?? null),
            'itineraries_loaded' => array_key_exists('itineraries', $order),
            'ticket_number' => $ticketNumbers !== [] ? implode(', ', $ticketNumbers) : null,
            'booking_number' => $bookingNumbers !== [] ? implode(', ', $bookingNumbers) : null,
            'service_url' => ServiceUrlExtractor::forProvider('aviasales', $id, $order, $ticket),
            'status' => $order['status'] ?? null,
            'status_code' => $order['status'] ?? null,
            'passenger' => $traveler['display'] !== ''
                ? $traveler['display']
                : trim("{$last} {$first} {$middle}"),
            'passenger_first_name' => $first,
            'passenger_last_name' => $last,
            'passenger_middle_name' => $middle,
            'route' => $route,
            'departure_location' => $type !== ServiceType::ACCOMMODATION ? $departure : null,
            'arrival_location' => $type !== ServiceType::ACCOMMODATION ? $arrival : null,
            'departure_at' => $type !== ServiceType::ACCOMMODATION ? $this->dateValue($departureAt) : null,
            'arrival_at' => $type !== ServiceType::ACCOMMODATION ? $this->dateValue($arrivalAt) : null,
            'check_in' => $type === ServiceType::ACCOMMODATION ? $this->dateValue($departureAt) : null,
            'check_out' => $type === ServiceType::ACCOMMODATION ? $this->dateValue($arrivalAt) : null,
            'amount' => isset($order['price']) ? (float) $order['price'] : null,
            'currency' => $order['currency'] ?? 'RUB',
            'company_name' => $company['name'] ?? $company['title'] ?? null,
            'company_inn' => $company['inn'] ?? null,
            'hotel_name' => $hotelName,
            'hotel_address' => $hotelAddress,
            'room_name' => $roomName,
            'created_at' => $order['created_at'] ?? null,
            'updated_at' => $order['updated_at'] ?? null,
            'project' => $order['cost_center'] ?? null,
            'buyer' => $buyer,
            'raw_json' => json_encode($this->scrub($order), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function shortOrderId(string $id): string
    {
        $id = trim($id);
        return strtoupper(substr($id, 0, 8));
    }

    private function cleanRailStationName(string|int|float|null $value): string|int|float|null
    {
        if (!is_string($value)) {
            return $value;
        }

        return trim((string) preg_replace('/\s+пасс(?:ажирс(?:кий)?)?\.?$/iu', '', trim($value)));
    }

    private function cleanRailRoute(string $route): string
    {
        $parts = preg_split('/\s*(?:—|–|\s-\s)\s*/u', trim($route));
        if (!is_array($parts) || count($parts) < 2) {
            return (string) $this->cleanRailStationName($route);
        }

        $parts = array_map(
            fn (string $part): string => (string) $this->cleanRailStationName($part),
            $parts,
        );

        return implode(' — ', $parts);
    }

    private function routeLabel(string|int|float|null $departure, string|int|float|null $arrival): ?string
    {
        $departure = trim((string) $departure);
        $arrival = trim((string) $arrival);
        if ($departure === '' && $arrival === '') {
            return null;
        }
        if ($departure === '' || $arrival === '') {
            return $departure !== '' ? $departure : $arrival;
        }

        return "{$departure} — {$arrival}";
    }

    private function companyDetails(): array
    {
        if ($this->companyDetailsLoaded) {
            return $this->companyDetails ?? [];
        }
        $this->companyDetailsLoaded = true;
        $companyId = trim((string) ($this->credentials['company_id'] ?? ''));
        if ($companyId === '') {
            return [];
        }

        try {
            $response = $this->request([], self::COMPANIES_ENDPOINT . '/' . rawurlencode($companyId));
            if (isset($response['id']) || isset($response['name']) || isset($response['inn'])) {
                return $this->companyDetails = $response;
            }
            $companies = array_is_list($response)
                ? $response
                : (is_array($response['results'] ?? null) ? $response['results'] : []);
            foreach ($companies as $company) {
                if (is_array($company) && (string) ($company['id'] ?? '') === $companyId) {
                    return $this->companyDetails = $company;
                }
            }
        } catch (\Throwable) {
            // Company metadata is optional and must not interrupt ticket synchronization.
        }

        return $this->companyDetails = [];
    }

    private function firstValue(array $source, array $paths): string|int|float|null
    {
        foreach ($paths as $path) {
            $value = $source;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return is_bool($value) ? (int) $value : $value;
            }
        }

        return null;
    }

    private function dateValue(string|int|float|null $value): string|int|float|null
    {
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
            $timestamp = (int) $value;
            if ($timestamp > 1_000_000_000_000) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            if ($timestamp > 0) {
                return (new \DateTimeImmutable('@' . $timestamp))->format(DATE_ATOM);
            }
        }

        return $value;
    }

    private function needsOrderDetails(TravelItem $item): bool
    {
        $passenger = trim((string) ($item->data['passenger'] ?? ''));
        $buyer = trim((string) ($item->data['buyer'] ?? ''));
        $travelerMissing = $passenger === '' || ($buyer !== '' && $passenger === $buyer);
        $ticketMissing = in_array($item->serviceType, [ServiceType::AIR, ServiceType::RAIL], true)
            && trim((string) ($item->data['ticket_number'] ?? '')) === '';
        $itinerariesMissing = in_array($item->serviceType, [ServiceType::AIR, ServiceType::RAIL], true)
            && empty($item->data['itineraries_loaded']);
        $routeNeedsDetails = $item->serviceType === ServiceType::AIR
            && $this->isIataOnlyRoute((string) ($item->data['route'] ?? ''));

        return $travelerMissing || $ticketMissing || $itinerariesMissing || $routeNeedsDetails;
    }

    private function isIataCode(string $value): bool
    {
        return preg_match('/^[A-Z]{3}$/', trim($value)) === 1;
    }

    private function isIataOnlyRoute(string $value): bool
    {
        return preg_match('/^[A-Z]{3}(?:\s*[—–-]\s*[A-Z]{3})+$/u', trim($value)) === 1;
    }

    /** @return list<string> */
    private function itineraryUrls(mixed $value, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }
        if (is_string($value)) {
            $url = trim($value);
            return $url === '' ? [] : [$url];
        }
        if (!is_array($value)) {
            return [];
        }

        foreach (['file', 'url', 'download_url', 'downloadUrl', 'href'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && trim($value[$key]) !== '') {
                return [trim($value[$key])];
            }
        }
        if (!array_is_list($value)) {
            return [];
        }

        $urls = [];
        foreach ($value as $item) {
            foreach ($this->itineraryUrls($item, $depth + 1) as $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    private function identifiers(array $sources, array $keys, bool $allowNumberInsideTicket = false): array
    {
        $allowed = array_fill_keys(array_map($this->normalizeKey(...), $keys), true);
        $values = [];
        foreach ($sources as $source) {
            $this->collectIdentifiers($source, $allowed, $values, $allowNumberInsideTicket);
        }

        return array_slice(array_values(array_unique(array_filter(
            $values,
            static fn (string $value): bool => $value !== '',
        ))), 0, 20);
    }

    private function collectIdentifiers(
        mixed $value,
        array $allowed,
        array &$result,
        bool $allowNumberInsideTicket,
        int $depth = 0,
        bool $insideTicketContainer = false,
    ): void {
        if (!is_array($value) || $depth > 8) {
            return;
        }
        foreach ($value as $key => $item) {
            $normalizedKey = $this->normalizeKey((string) $key);
            if (is_scalar($item)) {
                if (isset($allowed[$normalizedKey])
                    || ($allowNumberInsideTicket && $insideTicketContainer && $normalizedKey === 'number')
                ) {
                    $candidate = trim((string) $item);
                    if ($candidate !== '') {
                        $result[] = $candidate;
                    }
                }
                continue;
            }
            if (is_array($item)) {
                $ticketContainer = $insideTicketContainer
                    || str_contains($normalizedKey, 'ticket');
                $this->collectIdentifiers(
                    $item,
                    $allowed,
                    $result,
                    $allowNumberInsideTicket,
                    $depth + 1,
                    $ticketContainer,
                );
            }
        }
    }

    private function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $key));
    }

    /** @return array{first: string, last: string, middle: string, display: string} */
    private function traveler(array $order, array $ticket, array $buyer): array
    {
        $sources = [
            $ticket,
            is_array($order['parameters'] ?? null) ? $order['parameters'] : [],
            is_array($order['additionals'] ?? null) ? $order['additionals'] : [],
        ];
        foreach ($sources as $source) {
            $person = $this->findTraveler($source);
            if ($person !== null) {
                return $person;
            }
        }

        $employers = $order['employers'] ?? null;
        if (is_string($employers) && trim($employers) !== '') {
            return ['first' => '', 'last' => '', 'middle' => '', 'display' => trim($employers)];
        }
        if (is_array($employers)) {
            $person = $this->findTraveler(['employers' => $employers]);
            if ($person !== null) {
                return $person;
            }
        }

        return $this->personFromArray($buyer) ?? [
            'first' => '',
            'last' => '',
            'middle' => '',
            'display' => '',
        ];
    }

    /** @return array{first: string, last: string, middle: string, display: string}|null */
    private function findTraveler(array $value, int $depth = 0, bool $personContext = false): ?array
    {
        if ($depth > 5) {
            return null;
        }
        $person = $this->personFromArray($value, $personContext);
        if ($person !== null) {
            return $person;
        }

        $containers = [
            'passenger', 'passengers', 'traveller', 'travellers', 'traveler', 'travelers',
            'guest', 'guests', 'tourist', 'tourists', 'employer', 'employers', 'employees',
        ];
        foreach ($containers as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $nested = $value[$key];
            if (is_string($nested) && trim($nested) !== '') {
                return ['first' => '', 'last' => '', 'middle' => '', 'display' => trim($nested)];
            }
            if (!is_array($nested)) {
                continue;
            }
            if (array_is_list($nested)) {
                foreach ($nested as $candidate) {
                    if (is_array($candidate) && ($person = $this->findTraveler($candidate, $depth + 1, true)) !== null) {
                        return $person;
                    }
                    if (is_string($candidate) && trim($candidate) !== '') {
                        return ['first' => '', 'last' => '', 'middle' => '', 'display' => trim($candidate)];
                    }
                }
            } elseif (($person = $this->findTraveler($nested, $depth + 1, true)) !== null) {
                return $person;
            }
        }

        return null;
    }

    /** @return array{first: string, last: string, middle: string, display: string}|null */
    private function personFromArray(array $value, bool $personContext = false): ?array
    {
        $first = trim((string) ($value['first_name'] ?? $value['firstName'] ?? ($personContext ? ($value['name'] ?? '') : '')));
        $last = trim((string) ($value['last_name'] ?? $value['lastName'] ?? $value['surname'] ?? ''));
        $middle = trim((string) ($value['middle_name'] ?? $value['middleName'] ?? $value['patronymic'] ?? ''));
        $display = trim((string) (
            $value['full_name']
            ?? $value['fullName']
            ?? $value['fio']
            ?? $value['passenger_name']
            ?? $value['guest_name']
            ?? ''
        ));
        if ($first === '' && $last === '' && $middle === '' && $display === '') {
            return null;
        }
        if ($display === '') {
            $display = trim("{$last} {$first} {$middle}");
        }

        return compact('first', 'last', 'middle', 'display');
    }

    private function scrub(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['password', 'document', 'document_number', 'phone', 'email'], true)) {
                $value[$key] = '[redacted]';
            } elseif (is_array($item)) {
                $value[$key] = $this->scrub($item);
            }
        }
        return $value;
    }
}
