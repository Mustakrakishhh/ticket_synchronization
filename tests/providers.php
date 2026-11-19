<?php

declare(strict_types=1);

use App\Domain\ServiceType;
use App\Provider\Aviasales\AviasalesProvider;
use App\Provider\Smartway\SmartwayProvider;
use App\Provider\ServiceUrlExtractor;
use App\Provider\Trivio\TrivioClient;
use App\Provider\Trivio\TrivioJsonClient;
use App\Provider\Trivio\TrivioNormalizer;
use App\Provider\Trivio\TrivioProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$from = new DateTimeImmutable('2026-01-01T00:00:00Z');
$to = new DateTimeImmutable('2026-01-02T00:00:00Z');

$aviasalesRequest = [];
$aviasales = new AviasalesProvider(
    [
        'api_key' => 'secret-api-key',
        'company_id' => 'company-uuid',
    ],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$aviasalesRequest): MockResponse {
        if (str_contains($url, '/api/companies/')) {
            return new MockResponse(json_encode([
                'id' => 'company-uuid', 'name' => 'Adara Corp', 'inn' => '7701234567',
            ], JSON_THROW_ON_ERROR));
        }
        if ($aviasalesRequest === []) {
            $aviasalesRequest = compact('method', 'url', 'options');
        }
        return new MockResponse(json_encode([
        'count' => 1,
        'next' => null,
        'results' => [[
            'id' => 'order-1',
            'order_url' => 'https://b2b.aviasales.ru/panel/orders/order-1',
            'type' => 'hotel',
            'status' => 'finished',
            'price' => '5000.00',
            'ticket' => [
                'name' => 'Hostel',
                'address' => 'Moscow, Test street 1',
                'check_in' => '2026-01-01T14:00:00+03:00',
                'check_out' => '2026-01-02T12:00:00+03:00',
                'proposal' => ['name' => 'Double room'],
                'booking_number' => 'H-1',
                'guests' => [[
                    'name' => 'Пётр',
                    'surname' => 'Петров',
                    'documents' => [['number' => 'PASSPORT-777']],
                ]],
                'tickets' => [
                    ['number' => 'TICKET-001', 'pnr' => 'PNR-001'],
                    ['ticketNumber' => 'TICKET-002'],
                ],
            ],
            'user' => ['first_name' => 'Иван', 'last_name' => 'Иванов'],
        ], [
            'id' => 'additional-order',
            'type' => 'additional_service',
            'title' => 'Багаж',
            'status' => 'finished',
        ]],
        ], JSON_THROW_ON_ERROR));
    }),
);
$aviaResult = $aviasales->fetch(null, $from, $to);
$aviaItems = iterator_to_array((static function (iterable $items): iterable { yield from $items; })($aviaResult['items']));
if (count($aviaItems) !== 2
    || $aviaItems[0]->serviceType !== ServiceType::ACCOMMODATION
    || $aviaItems[1]->serviceType !== ServiceType::ADDITIONAL_SERVICE
    || $aviaItems[1]->externalId !== 'other:additional-order'
    || ($aviaItems[0]->data['passenger'] ?? null) !== 'Петров Пётр'
    || ($aviaItems[0]->data['buyer'] ?? null) !== 'Иванов Иван'
    || ($aviaItems[0]->data['short_order_id'] ?? null) !== 'ORDER-1'
    || ($aviaItems[0]->data['ticket_number'] ?? null) !== 'TICKET-001, TICKET-002'
    || ($aviaItems[0]->data['booking_number'] ?? null) !== 'H-1, PNR-001'
    || ($aviaItems[0]->data['service_url'] ?? null) !== 'https://b2b.aviasales.ru/panel/orders/order-1'
    || ($aviaItems[0]->data['company_name'] ?? null) !== 'Adara Corp'
    || ($aviaItems[0]->data['company_inn'] ?? null) !== '7701234567'
    || ($aviaItems[0]->data['hotel_name'] ?? null) !== 'Hostel'
    || ($aviaItems[0]->data['hotel_address'] ?? null) !== 'Moscow, Test street 1'
    || ($aviaItems[0]->data['room_name'] ?? null) !== 'Double room'
    || ($aviaItems[0]->data['departure_at'] ?? null) !== null
    || ($aviaItems[0]->data['arrival_at'] ?? null) !== null
    || ($aviaItems[0]->data['check_in'] ?? null) !== '2026-01-01T14:00:00+03:00'
    || ($aviaItems[0]->data['check_out'] ?? null) !== '2026-01-02T12:00:00+03:00'
    || str_contains((string) ($aviaItems[0]->data['ticket_number'] ?? ''), 'PASSPORT')
) {
    throw new RuntimeException('Aviasales adapter test failed.');
}
if (($aviasalesRequest['options']['query']['current_company'] ?? null) !== 'company-uuid') {
    throw new RuntimeException('Aviasales company filter test failed.');
}
if (($aviasalesRequest['options']['query']['company'] ?? null) !== 'company-uuid'
    || ($aviasalesRequest['options']['query']['created_at_from'] ?? null) !== '2026-01-01'
    || ($aviasalesRequest['options']['query']['created_at_to'] ?? null) !== '2026-01-02'
) {
    throw new RuntimeException('Aviasales order filters test failed.');
}

$detailRequests = [];
$detailProvider = new AviasalesProvider(
    ['api_key' => 'secret-api-key', 'company_id' => 'company-uuid'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$detailRequests): MockResponse {
        $detailRequests[] = compact('method', 'url', 'options');
        if (str_contains($url, '/api/companies/')) {
            return new MockResponse(json_encode([
                'id' => 'company-uuid', 'name' => 'Adara Corp', 'inn' => '7701234567',
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, '/api/orders/rail-order')) {
            return new MockResponse(json_encode([
                'id' => 'rail-order',
                'type' => 'train',
                'status' => 'finished',
                'price' => '2500.00',
                'ticket' => [
                    'passengers' => [['firstName' => 'Анна', 'lastName' => 'Смирнова']],
                    'tickets' => [['number' => '70345678901234']],
                    'segments' => [[
                        'train' => ['items' => [[
                            'departure_station_name' => 'Красноярск Пасс',
                            'arrival_station_name' => 'Иркутск Пассажирс',
                            'departure_date_time' => '2026-01-01T08:00:00+03:00',
                            'arrival_date_time' => '2026-01-01T20:00:00+03:00',
                        ]]],
                    ]],
                ],
                'booking_params' => ['booking_id' => 'RAIL-BOOK-1'],
                'itineraries' => ['https://b2b.aviasales.ru/media/rail-ticket.pdf'],
            ], JSON_THROW_ON_ERROR));
        }

        return new MockResponse(json_encode([
            'count' => 1,
            'next' => null,
            'results' => [[
                'id' => 'rail-order',
                'type' => 'train',
                'status' => 'finished',
                'price' => '2500.00',
                'ticket' => [],
                'user' => ['first_name' => 'Иван', 'last_name' => 'Покупатель'],
            ]],
        ], JSON_THROW_ON_ERROR));
    }),
);
$detailResult = $detailProvider->fetch(null, $from, $to);
$detailItem = $detailResult['items'][0] ?? null;
if (!$detailItem instanceof \App\Domain\TravelItem
    || ($detailItem->data['ticket_number'] ?? null) !== '70345678901234'
    || ($detailItem->data['passenger'] ?? null) !== 'Смирнова Анна'
    || ($detailItem->data['itineraries'][0] ?? null) !== 'https://b2b.aviasales.ru/media/rail-ticket.pdf'
    || empty($detailItem->data['itineraries_loaded'])
    || ($detailItem->data['booking_number'] ?? null) !== 'RAIL-BOOK-1'
    || ($detailItem->data['route'] ?? null) !== 'Красноярск — Иркутск'
    || ($detailItem->data['departure_at'] ?? null) !== '2026-01-01T08:00:00+03:00'
    || ($detailItem->data['arrival_at'] ?? null) !== '2026-01-01T20:00:00+03:00'
    || count($detailRequests) !== 3
    || !str_contains($detailRequests[2]['url'], '/api/orders/rail-order')
    || ($detailRequests[2]['options']['query']['current_company'] ?? null) !== 'company-uuid'
) {
    throw new RuntimeException('Aviasales detailed order enrichment test failed.');
}

$iataRequests = [];
$iataProvider = new AviasalesProvider(
    ['api_key' => 'secret-api-key', 'company_id' => 'company-uuid'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$iataRequests): MockResponse {
        $iataRequests[] = $url;
        if (str_contains($url, '/api/companies/')) {
            return new MockResponse(json_encode([
                'id' => 'company-uuid', 'name' => 'Adara Corp', 'inn' => '7701234567',
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, '/api/orders/air-order-uuid')) {
            return new MockResponse(json_encode([
                'id' => 'air-order-uuid',
                'type' => 'avia',
                'internal' => ['search' => ['segments' => [[
                    'from' => ['name' => 'Южно-Сахалинск', 'code' => 'UUS'],
                    'to' => ['name' => 'Краснодар', 'code' => 'KRR'],
                ]]]],
            ], JSON_THROW_ON_ERROR));
        }

        return new MockResponse(json_encode([
            'count' => 1,
            'next' => null,
            'results' => [[
                'id' => 'air-order-uuid',
                'type' => 'avia',
                'status' => 'finished',
                'ticket' => [
                    'route' => 'UUS — KRR',
                    'departure_city' => 'UUS',
                    'arrival_city' => 'KRR',
                    'tickets' => [['number' => '9990000000001']],
                    'passengers' => [['firstName' => 'Иван', 'lastName' => 'Тестов']],
                ],
                'itineraries' => [],
            ]],
        ], JSON_THROW_ON_ERROR));
    }),
);
$iataResult = $iataProvider->fetch(null, $from, $to);
$iataItem = $iataResult['items'][0] ?? null;
if (!$iataItem instanceof \App\Domain\TravelItem
    || ($iataItem->data['departure_location'] ?? null) !== 'Южно-Сахалинск'
    || ($iataItem->data['arrival_location'] ?? null) !== 'Краснодар'
    || ($iataItem->data['route'] ?? null) !== 'Южно-Сахалинск — Краснодар'
    || count($iataRequests) !== 3
    || !str_contains($iataRequests[2], '/api/orders/air-order-uuid')
) {
    throw new RuntimeException('Aviasales IATA route enrichment test failed.');
}
$expectedAuthorization = 'Authorization: Token secret-api-key';
$requestHeaders = $aviasalesRequest['options']['normalized_headers'] ?? [];
$authorization = $requestHeaders['authorization'][0] ?? '';
if ($authorization !== $expectedAuthorization) {
    throw new RuntimeException('Aviasales Token authorization test failed.');
}

$documentRequests = [];
$documentProvider = new AviasalesProvider(
    ['api_key' => 'secret-api-key', 'company_id' => 'company-uuid'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$documentRequests): MockResponse {
        $documentRequests[] = compact('method', 'url', 'options');
        if (str_contains($url, '/api/orders/order-1')) {
            return new MockResponse(json_encode([
                'id' => 'order-1',
                'itineraries' => [
                    ['file' => 'https://b2b.aviasales.ru/media/ticket.pdf'],
                ],
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, 'b2b.aviasales.ru/media/')) {
            return new MockResponse(json_encode([
                'url' => 'https://files.example.test/signed-ticket.pdf',
            ], JSON_THROW_ON_ERROR), [
                'response_headers' => ['Content-Type: application/json'],
            ]);
        }

        return new MockResponse("%PDF-1.4\nmock ticket", [
            'response_headers' => [
                'Content-Type: application/pdf',
                'Content-Disposition: attachment; filename="route-receipt.pdf"',
            ],
        ]);
    }),
);
$documentItem = new \App\Domain\TravelItem('aviasales', 'air:order-1', ServiceType::AIR, [
    'order_id' => 'order-1',
]);
$ticketDocument = $documentProvider->ticketDocument($documentItem);
if ($ticketDocument === null
    || $ticketDocument->filename !== 'route-receipt.pdf'
    || $ticketDocument->contentType !== 'application/pdf'
    || count($documentRequests) !== 3
    || $documentRequests[0]['method'] !== 'GET'
    || !str_contains($documentRequests[0]['url'], '/api/orders/order-1')
    || isset($documentRequests[0]['options']['body'])
    || ($documentRequests[0]['options']['query']['current_company'] ?? null) !== 'company-uuid'
    || isset($documentRequests[0]['options']['query']['company'])
    || $documentRequests[1]['method'] !== 'GET'
    || ($documentRequests[1]['options']['query']['current_company'] ?? null) !== 'company-uuid'
    || ($documentRequests[1]['options']['normalized_headers']['accept'][0] ?? null) !== 'Accept: */*'
    || $documentRequests[2]['method'] !== 'GET'
    || !str_contains($documentRequests[2]['url'], 'files.example.test/signed-ticket.pdf')
    || isset($documentRequests[2]['options']['query']['current_company'])
    || isset($documentRequests[2]['options']['normalized_headers']['authorization'])
) {
    throw new RuntimeException('Aviasales ticket document test failed: ' . json_encode([
        'filename' => $ticketDocument?->filename,
        'contentType' => $ticketDocument?->contentType,
        'requests' => $documentRequests,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

$missingDocumentRequests = 0;
$missingDocuments = new AviasalesProvider(
    ['api_key' => 'secret-api-key', 'company_id' => 'company-uuid'],
    new MockHttpClient(static function () use (&$missingDocumentRequests): MockResponse {
        $missingDocumentRequests++;
        return new MockResponse(json_encode([
            'id' => 'order-1',
            'itineraries' => [],
        ], JSON_THROW_ON_ERROR));
    }),
);
$missingDocument = $missingDocuments->ticketDocument($documentItem);
$hotelDocument = $missingDocuments->ticketDocument($aviaItems[0]);
if ($missingDocumentRequests !== 1 || $missingDocument !== null || $hotelDocument !== null) {
    throw new RuntimeException('Aviasales missing itinerary test failed.');
}

$connectionRequests = [];
$aviasalesConnection = new AviasalesProvider(
    ['api_key' => 'secret-api-key', 'company_id' => 'company-uuid'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$connectionRequests): MockResponse {
        $connectionRequests[] = compact('method', 'url', 'options');
        if (str_contains($url, '/api/companies')) {
            return new MockResponse(json_encode([
                ['id' => 'company-uuid', 'name' => 'Test company'],
            ], JSON_THROW_ON_ERROR));
        }

        return new MockResponse(json_encode([
            'count' => 0,
            'next' => null,
            'results' => [],
        ], JSON_THROW_ON_ERROR));
    }),
);
$aviasalesConnection->testConnection();
if (count($connectionRequests) !== 1
    || ($connectionRequests[0]['options']['query']['current_company'] ?? null) !== 'company-uuid'
    || ($connectionRequests[0]['options']['query']['company'] ?? null) !== 'company-uuid'
    || ($connectionRequests[0]['options']['query']['page_size'] ?? null) !== 1
    || isset($connectionRequests[0]['options']['query']['created_at_from'])
    || isset($connectionRequests[0]['options']['query']['created_at_to'])
) {
    throw new RuntimeException('Aviasales connection diagnostics test failed.');
}

$forbiddenRequests = 0;
$forbidden = new AviasalesProvider(
    ['api_key' => 'invalid-key', 'company_id' => 'company-uuid'],
    new MockHttpClient(static function () use (&$forbiddenRequests): MockResponse {
        $forbiddenRequests++;
        return new MockResponse(
            json_encode(['detail' => 'Invalid token.'], JSON_THROW_ON_ERROR),
            ['http_code' => 403],
        );
    }),
);
try {
    $forbidden->fetch(null, $from, $to);
    throw new RuntimeException('Aviasales 403 test did not fail.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'Invalid token.') || $forbiddenRequests !== 1) {
        throw new RuntimeException('Aviasales 403 diagnostics test failed.', previous: $exception);
    }
}

$smartwayRequests = [];
$smartway = new SmartwayProvider(
    ['username' => 'user@example.test', 'password' => 'pass'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$smartwayRequests): MockResponse {
        $payload = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $smartwayRequests[] = compact('method', 'url', 'options', 'payload');
        $result = match ($payload['method'] ?? '') {
            'ping.ping' => "I'm alive",
            'trip.item_voucher' => ['content' => base64_encode("%PDF-1.4\nsmartway voucher")],
            default => [
                'last_version_id' => 42,
                'trips' => [[
                    'id' => 11,
                    'status' => 'Completed',
                    'items' => [[
                        'id' => 22,
                        'service_type' => 'Air',
                        'number' => '421-0001',
                        'status' => 'Created',
                        'amount' => 3319,
                        'book_date' => '2026-01-01T09:00:00',
                        'last_change_date' => '2026-01-01T09:05:00',
                        'travellers' => [
                            ['name' => 'Иван', 'surname' => 'Иванов'],
                            ['name' => 'Пётр', 'surname' => 'Петров'],
                        ],
                        'info' => [[
                            'segments' => [[
                                'date_depart' => '2026-01-01T10:00:00',
                                'date_arrive' => '2026-01-01T12:00:00',
                                'place_depart' => ['city' => 'Москва'],
                                'place_arrive' => ['city' => 'Казань'],
                            ]],
                        ]],
                    ], [
                        'id' => 23,
                        'service_type' => 'AirAdditionalService',
                        'name' => 'Выбор места',
                        'number' => '421-0001',
                    ]],
                ]],
            ],
        };

        return new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => $payload['id'] ?? 1,
            'result' => $result,
        ], JSON_THROW_ON_ERROR));
    }),
);
$smartway->testConnection();
$smartResult = $smartway->fetch('0', $from, $to);
$smartDocument = $smartway->ticketDocument($smartResult['items'][0]);
if (count($smartResult['items']) !== 2
    || $smartResult['items'][0]->externalId !== 'air:22'
    || $smartResult['items'][1]->serviceType !== ServiceType::ADDITIONAL_SERVICE
    || $smartResult['items'][1]->externalId !== 'air:23'
    || $smartResult['nextCursor'] !== '42'
    || ($smartResult['items'][0]->data['passenger'] ?? '') !== 'Иванов Иван, Петров Пётр'
    || ($smartResult['items'][0]->data['route'] ?? '') !== 'Москва → Казань'
    || ($smartResult['items'][0]->data['departure_location'] ?? null) !== 'Москва'
    || ($smartResult['items'][0]->data['arrival_location'] ?? null) !== 'Казань'
    || ($smartResult['items'][0]->data['service_url'] ?? null) !== 'https://po.smartway.today/trip/11'
    || $smartDocument?->contentType !== 'application/pdf'
    || $smartDocument?->content !== "%PDF-1.4\nsmartway voucher"
    || ($smartwayRequests[0]['payload']['method'] ?? null) !== 'ping.ping'
    || ($smartwayRequests[0]['payload']['params']['query'] ?? null) !== []
    || ($smartwayRequests[1]['payload']['method'] ?? null) !== 'trip.get_after_change'
    || ($smartwayRequests[1]['payload']['params']['query'] ?? null) !== 0
    || ($smartwayRequests[1]['payload']['params']['extended'] ?? null) !== true
    || ($smartwayRequests[1]['payload']['params']['authorization']['username'] ?? null) !== 'user@example.test'
    || ($smartwayRequests[2]['payload']['method'] ?? null) !== 'trip.item_voucher'
    || ($smartwayRequests[2]['payload']['params']['query'] ?? null) !== 22
) {
    throw new RuntimeException('Smartway adapter test failed.');
}

$smartwayRangeRequest = [];
$smartwayRange = new SmartwayProvider(
    ['username' => 'user@example.test', 'password' => 'pass'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$smartwayRangeRequest): MockResponse {
        $smartwayRangeRequest = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        return new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => $smartwayRangeRequest['id'] ?? 1,
            'result' => ['last_version_id' => 10, 'trips' => null],
        ], JSON_THROW_ON_ERROR));
    }),
);
$smartwayRangeResult = $smartwayRange->fetch(null, $from, $to);
if (($smartwayRangeRequest['method'] ?? null) !== 'trip.get_by_range'
    || ($smartwayRangeRequest['params']['query']['extended'] ?? null) !== true
    || ($smartwayRangeRequest['params']['query']['start_date'] ?? null) !== '2026-01-01T00:00:00'
    || ($smartwayRangeRequest['params']['query']['end_date'] ?? null) !== '2026-01-02T03:00:00'
    || array_key_exists('extended', $smartwayRangeRequest['params'])
    || $smartwayRangeResult['nextCursor'] !== '10'
) {
    throw new RuntimeException('Smartway range request contract test failed.');
}

$smartwayEmptyRangeRequests = [];
$smartwayEmptyRange = new SmartwayProvider(
    ['username' => 'user@example.test', 'password' => 'pass'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$smartwayEmptyRangeRequests): MockResponse {
        $payload = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $smartwayEmptyRangeRequests[] = $payload;
        $requestNumber = count($smartwayEmptyRangeRequests);
        $response = $requestNumber === 1
            ? [
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? 1,
                'error' => ['code' => -32000, 'message' => 'status code = 204', 'data' => null],
            ]
            : [
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? 1,
                'result' => [
                    'last_version_id' => 77,
                    'trips' => [[
                        'id' => 33,
                        'status' => 'Completed',
                        'items' => [[
                            'id' => 44,
                            'service_type' => 'Train',
                            'number' => 'TRAIN-44',
                            'status' => 'Created',
                            'travellers' => [['name' => 'Анна', 'surname' => 'Иванова']],
                            'info' => ['station_depart' => 'Москва', 'station_arrive' => 'Казань'],
                        ]],
                    ]],
                ],
            ];

        return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR));
    }),
);
$smartwayAfterEmptyRange = $smartwayEmptyRange->fetch(
    null,
    new DateTimeImmutable('2026-07-01T00:00:00Z'),
    new DateTimeImmutable('2026-07-21T23:59:59Z'),
);
if (count($smartwayEmptyRangeRequests) !== 2
    || count($smartwayAfterEmptyRange['items']) !== 1
    || $smartwayAfterEmptyRange['items'][0]->externalId !== 'rail:44'
    || $smartwayAfterEmptyRange['nextCursor'] !== '77'
) {
    throw new RuntimeException('Smartway empty intermediate range test failed.');
}

$smartwayAllEmptyRequests = [];
$smartwayAllEmpty = new SmartwayProvider(
    ['username' => 'user@example.test', 'password' => 'pass'],
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$smartwayAllEmptyRequests): MockResponse {
        $payload = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $smartwayAllEmptyRequests[] = $payload;
        return new MockResponse(json_encode([
            'jsonrpc' => '2.0',
            'id' => $payload['id'] ?? 1,
            'error' => ['code' => -32000, 'message' => 'status code = 204', 'data' => null],
        ], JSON_THROW_ON_ERROR));
    }),
);
$emptyFrom = new DateTimeImmutable('2026-08-01T00:00:00Z');
$emptyTo = new DateTimeImmutable('2026-08-03T12:34:56Z');
$smartwayAllEmptyResult = $smartwayAllEmpty->fetch(null, $emptyFrom, $emptyTo);
$smartwayTimestampCursorResult = $smartwayAllEmpty->fetch(
    $smartwayAllEmptyResult['nextCursor'],
    $emptyTo->modify('-2 hours'),
    $emptyTo->modify('+30 minutes'),
);
if ($smartwayAllEmptyResult['items'] !== []
    || $smartwayAllEmptyResult['nextCursor'] !== $emptyTo->format(DATE_ATOM)
    || $smartwayTimestampCursorResult['items'] !== []
    || count($smartwayAllEmptyRequests) !== 2
    || array_filter(
        $smartwayAllEmptyRequests,
        static fn (array $request): bool => ($request['method'] ?? '') !== 'trip.get_by_range',
    ) !== []
) {
    throw new RuntimeException('Smartway all-empty range cursor test failed.');
}

$trivioRequests = [];
$soapEnvelope = static fn (string $content): string => '<?xml version="1.0" encoding="UTF-8"?>'
    . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" '
    . 'xmlns:ns1="https://corporate-api.trivio.ru/api/integration">'
    . '<SOAP-ENV:Body>' . $content . '</SOAP-ENV:Body></SOAP-ENV:Envelope>';
$trivioHttp = new MockHttpClient(static function (string $method, string $url, array $options) use (&$trivioRequests, $soapEnvelope): MockResponse {
    $trivioRequests[] = compact('method', 'url', 'options');
    $body = (string) ($options['body'] ?? '');
    if (str_contains($body, '<int:getTicketTransactionInfo>')) {
        return new MockResponse($soapEnvelope(
            '<ns1:getTicketTransactionInfoResponse><return><ns1:transactions><ns1:tickettransactiondto>'
            . '<ns1:transaction_id>374978</ns1:transaction_id><ns1:transaction_type>1</ns1:transaction_type>'
            . '<ns1:transaction_date>20.12.2021 16:19:25</ns1:transaction_date><ns1:status>2</ns1:status>'
            . '<ns1:status_text>Покупка</ns1:status_text><ns1:tracking_status_text>ticketed</ns1:tracking_status_text>'
            . '<ns1:service_id>153153</ns1:service_id><ns1:ticket_id>103002</ns1:ticket_id>'
            . '<ns1:order_id>881757</ns1:order_id><ns1:number>7894556230147</ns1:number>'
            . '<ns1:itinerary_desc>Санкт-Петербург — Москва</ns1:itinerary_desc>'
            . '<ns1:itinerary_start>22.12.2021 10:20:00</ns1:itinerary_start>'
            . '<ns1:itinerary_end>22.12.2021 12:20:00</ns1:itinerary_end>'
            . '<ns1:first_name>Дмитрий</ns1:first_name><ns1:sur_name>Иванов</ns1:sur_name>'
            . '<ns1:middle_name>Сергеевич</ns1:middle_name><ns1:sum>150</ns1:sum>'
            . '</ns1:tickettransactiondto></ns1:transactions><ns1:result>true</ns1:result>'
            . '<ns1:locale>ru_RU</ns1:locale></return></ns1:getTicketTransactionInfoResponse>',
        ));
    }
    if (str_contains($body, 'printBlank')) {
        return new MockResponse($soapEnvelope(
            '<ns1:printBlankResponse><return><ns1:data>' . base64_encode("%PDF-1.4\ntrivio ticket") . '</ns1:data>'
            . '<ns1:extension>pdf</ns1:extension><ns1:result>true</ns1:result>'
            . '<ns1:locale>ru_RU</ns1:locale></return></ns1:printBlankResponse>',
        ));
    }
    if (str_contains($body, 'integrationFeedback')) {
        throw new RuntimeException('Trivio feedback must never be sent by Bitrix24 synchronization.');
    }
    return new MockResponse('', ['http_code' => 500]);
});
$trivio = new TrivioClient('integration-token', $trivioHttp);
$trivio->testConnection();
$trivioProvider = new TrivioProvider(['token' => 'integration-token'], $trivio);
$trivioBatch = $trivioProvider->fetch(null, $from, $to);
$trivioItem = $trivioBatch['items'][0] ?? null;
$trivioDocument = $trivioItem instanceof \App\Domain\TravelItem
    ? $trivioProvider->ticketDocument($trivioItem)
    : null;
$trivioAfterCursor = $trivioProvider->fetch('999999', $from, $to);
if (!$trivioItem instanceof \App\Domain\TravelItem
    || $trivioItem->externalId !== 'air:103002'
    || ($trivioItem->data['ticket_number'] ?? null) !== '7894556230147'
    || ($trivioItem->data['passenger'] ?? null) !== 'Иванов Дмитрий Сергеевич'
    || !array_key_exists('company_inn', $trivioItem->data)
    || $trivioItem->data['company_inn'] !== null
    || ($trivioBatch['nextCursor'] ?? null) !== null
    || $trivioDocument?->filename !== 'trivio-ticket-103002.pdf'
    || $trivioDocument?->content !== "%PDF-1.4\ntrivio ticket"
    || count($trivioAfterCursor['items'] ?? []) !== 1
    || ($trivioAfterCursor['items'][0]->externalId ?? null) !== 'air:103002'
    || $trivioProvider instanceof \App\Provider\ProcessingFeedbackProviderInterface
    || $trivioItem->checksum() !== $trivioAfterCursor['items'][0]->checksum()
    || count($trivioRequests) !== 4
    || !str_contains((string) ($trivioRequests[0]['options']['body'] ?? ''), '<token>integration-token</token>')
    || !str_contains((string) ($trivioRequests[0]['options']['body'] ?? ''), '<version>1</version>')
    || str_contains((string) ($trivioRequests[0]['options']['body'] ?? ''), '<inn>')
    || !str_contains((string) ($trivioRequests[2]['options']['body'] ?? ''), '<serviceId>153153</serviceId>')
    || !str_contains((string) ($trivioRequests[2]['options']['body'] ?? ''), '<ticketId>103002</ticketId>')
) {
    throw new RuntimeException('Trivio Integration API test failed: ' . json_encode([
        'item' => $trivioItem?->fields(),
        'nextCursor' => $trivioBatch['nextCursor'] ?? null,
        'document' => $trivioDocument?->filename,
        'requestCount' => count($trivioRequests),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

$trivioJsonRequests = [];
$trivioJson = new TrivioJsonClient(
    'integration@example.test',
    'client-password',
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$trivioJsonRequests): MockResponse {
        $payload = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $trivioJsonRequests[] = compact('method', 'url', 'payload');
        $rpcMethod = (string) ($payload['method'] ?? '');
        $result = match ($rpcMethod) {
            'getSession' => [
                'result' => true,
                'error' => '',
                'currency_code' => 'RUB',
                'session' => ['session_id' => '12345678901234567890123456789012'],
                'has_two_step_auth' => false,
                'route_host' => 'corporate-api.trivio.ru',
            ],
            'getOrders' => [
                'result' => true,
                'error' => '',
                'currency_code' => 'RUB',
                'orders' => [['order_id' => 881757, 'order_date' => '2026-01-01']],
            ],
            'getOrder' => [
                'result' => true,
                'error' => '',
                'currency_code' => 'RUB',
                'orderDetailed' => [
                    'order' => ['order_id' => 881757, 'order_company_name' => 'Test company'],
                    'avia_services' => [],
                    'avia_service_tickets' => [],
                    'hotel_multi_services' => [[
                        'service_id' => 700,
                        'hotel_service_id' => 700,
                        'hotel_service_room_name' => 'Standard',
                        'hotel_service_check_in_date' => '2026-01-10',
                        'hotel_service_check_out_date' => '2026-01-11',
                        'hotel_multi_info' => ['hotel_name' => 'Test Hotel'],
                    ]],
                    'hotel_multi_roomers' => [[
                        'hotel_roomer_service_id' => 700,
                        'hotel_roomer_first_name' => 'Иван',
                        'hotel_roomer_last_name' => 'Иванов',
                    ]],
                ],
            ],
            default => ['result' => false, 'error' => 'Unexpected method'],
        };

        return new MockResponse(json_encode([
            'id' => $payload['id'] ?? $rpcMethod,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }),
);
$trivioJson->testConnection();
$trivioDetailedOrders = $trivioJson->detailedOrders($from, $to);
if (count($trivioDetailedOrders) !== 1
    || ($trivioDetailedOrders[0]['order']['order_id'] ?? null) !== 881757
    || ($trivioDetailedOrders[0]['_currency'] ?? null) !== 'RUB'
    || count($trivioJsonRequests) !== 3
    || ($trivioJsonRequests[1]['payload']['params']['searchParams']['order_date_from'] ?? null) !== '2026-01-01'
    || ($trivioJsonRequests[1]['payload']['params']['searchParams']['order_date_to'] ?? null) !== '2026-01-02'
    || ($trivioJsonRequests[2]['payload']['params']['orderId'] ?? null) !== 881757
) {
    throw new RuntimeException('Trivio Client JSON history test failed.');
}

$trivioPartialRequests = [];
$trivioPartial = new TrivioJsonClient(
    'client-login',
    'client-password',
    new MockHttpClient(static function (string $method, string $url, array $options) use (&$trivioPartialRequests): MockResponse {
        $payload = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        $trivioPartialRequests[] = $payload;
        $rpcMethod = (string) ($payload['method'] ?? '');
        $orderId = (int) ($payload['params']['orderId'] ?? 0);
        $result = match (true) {
            $rpcMethod === 'getSession' => [
                'result' => true,
                'error' => '',
                'currency_code' => 'RUB',
                'session' => ['session_id' => '12345678901234567890123456789012'],
                'has_two_step_auth' => false,
                'route_host' => 'corporate-api.trivio.ru',
            ],
            $rpcMethod === 'getOrders' => [
                'result' => true,
                'error' => '',
                'currency_code' => 'RUB',
                'orders' => [['order_id' => 1001], ['order_id' => 1002]],
            ],
            $rpcMethod === 'getOrder' && $orderId === 1001 => [
                'result' => false,
                'error' => 'Ошибка сервера (Код ошибки: test-code) &#x20;',
                'error_code' => 'COMMON_ERROR',
            ],
            $rpcMethod === 'getOrder' && $orderId === 1002 => [
                'result' => true,
                'error' => '',
                'currency_code' => 'RUB',
                'orderDetailed' => ['order' => ['order_id' => 1002]],
            ],
            default => ['result' => false, 'error' => 'Unexpected method'],
        };

        return new MockResponse(json_encode([
            'id' => $payload['id'] ?? $rpcMethod,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }),
);
$trivioPartialOrders = $trivioPartial->detailedOrders($from, $to);
$failedGetOrderCalls = array_values(array_filter(
    $trivioPartialRequests,
    static fn (array $request): bool => ($request['method'] ?? null) === 'getOrder'
        && (int) ($request['params']['orderId'] ?? 0) === 1001,
));
if (count($trivioPartialOrders) !== 1
    || ($trivioPartialOrders[0]['order']['order_id'] ?? null) !== 1002
    || count($failedGetOrderCalls) !== 3
    || !isset($trivioPartial->orderErrors()[1001])
    || !str_contains($trivioPartial->orderErrors()[1001], 'COMMON_ERROR')
    || str_contains($trivioPartial->orderErrors()[1001], '&#x20;')
) {
    throw new RuntimeException('Trivio per-order COMMON_ERROR resilience test failed.');
}

$safeServiceUrl = ServiceUrlExtractor::from(
    ['itineraries' => [['url' => 'https://files.example.test/ticket.pdf']]],
    ['ticket_url' => 'https://corporate.trivio.ru/orders/881757'],
);
if ($safeServiceUrl !== 'https://corporate.trivio.ru/orders/881757'
    || ServiceUrlExtractor::from(['url' => 'javascript:alert(1)']) !== null
    || ServiceUrlExtractor::forProvider('aviasales', 'ffc67a35-b10c-4db5-bcdb-1959b0bad57f')
        !== 'https://b2b.aviasales.ru/panel/orders/ffc67a35-b10c-4db5-bcdb-1959b0bad57f'
    || ServiceUrlExtractor::forProvider('smartway', 12299370)
        !== 'https://po.smartway.today/trip/12299370'
    || ServiceUrlExtractor::forProvider('trivio', 2858697)
        !== 'https://corporate.trivio.ru/order/show?order_id=2858697#data'
    || ServiceUrlExtractor::forProvider('trivio', 'invalid') !== null
) {
    throw new RuntimeException('Safe provider service URL extraction failed.');
}

$additionalNormalizer = new TrivioNormalizer();
$refundTransaction = $additionalNormalizer->normalizeTransaction([
    'transaction_id' => 5000,
    'transaction_type' => 1,
    'ticket_id' => 8000,
    'status' => 5,
    'status_text' => 'Возврат',
    'sum' => 700,
    'penalty' => 0,
    'penalty_sum' => 200,
    'non_refundable_fare' => 50,
    'non_refundable_taxes' => 25,
]);
$additionalTransaction = $additionalNormalizer->normalizeTransaction([
    'transaction_id' => 5001,
    'transaction_type' => 'airAdditionalService',
    'order_id' => 2858697,
    'service_id' => 7001,
    'ticket_id' => 8001,
    'number' => '9990000000002',
    'itinerary_desc' => 'Багаж → Дополнительная услуга; Багаж; Авиабилет; 9990000000002',
]);
$arbitraryItems = $additionalNormalizer->normalize([
    '_currency' => 'RUB',
    'order' => ['order_id' => 2858697],
    'arbitrary_services' => [[
        'arbitrary_service_id' => 9001,
        'service_id' => 9001,
        'title' => 'Выбор места',
        'description' => 'Дополнительная услуга к авиабилету',
    ], [
        'arbitrary_service_id' => 9002,
        'service_id' => 9002,
        'title' => 'Страхование',
        'description' => 'Полис для командировки',
    ]],
]);
if (!$refundTransaction instanceof \App\Domain\TravelItem
    || ($refundTransaction->data['expense_status'] ?? null) !== 'refunded'
    || ($refundTransaction->data['refund_amount'] ?? null) !== 700.0
    || ($refundTransaction->data['penalty_amount'] ?? null) !== 200.0
    || ($refundTransaction->data['non_refundable_amount'] ?? null) !== 75.0
    || ($refundTransaction->data['original_amount'] ?? null) !== 975.0
    || ($refundTransaction->data['net_amount'] ?? null) !== 275.0
    || !$additionalTransaction instanceof \App\Domain\TravelItem
    || $additionalTransaction->serviceType !== ServiceType::ADDITIONAL_SERVICE
    || $additionalTransaction->externalId !== 'air:8001'
    || count($arbitraryItems) !== 2
    || ($arbitraryItems[0]->serviceType ?? null) !== ServiceType::ADDITIONAL_SERVICE
    || ($arbitraryItems[0]->externalId ?? null) !== 'other:9001'
    || ($arbitraryItems[1]->serviceType ?? null) !== ServiceType::OTHER
    || ($arbitraryItems[1]->externalId ?? null) !== 'other:9002'
    || (ServiceType::all()[ServiceType::ADDITIONAL_SERVICE] ?? null) !== 'Дополнительные услуги (багаж, выбор места)'
) {
    throw new RuntimeException('Additional travel services classification failed.');
}

$busItems = (new TrivioNormalizer())->normalize([
    'order' => ['order_id' => 12345],
    // Deliberately reversed: tickets must join their own service, not the first row.
    'bus_services' => [[
        'service_id' => 202,
        'bus_service_id' => 22,
        'bus_result_departure_date' => '2026-10-21T00:00:00+03:00',
        'bus_result_arrival_date' => '2026-10-21T06:30:00+03:00',
        'bus_result_from' => 'Автостанция Казань',
        'bus_result_to' => 'Автостанция Уфа',
    ], [
        'service_id' => 101,
        'bus_service_id' => 11,
        'bus_result_departure_date' => '2026-10-20T22:15:00+03:00',
        'bus_result_arrival_date' => '2026-10-21T07:45:00+03:00',
        'service_city_from' => 'Москва',
        'service_city_to' => 'Казань',
        'bus_service_race_name' => 'Москва → Казань',
    ]],
    'bus_tickets' => [[
        'bus_ticket_id' => 501,
        'bus_ticket_service_id' => 11,
        'bus_ticket_number' => 'BUS-501',
        'bus_ticket_total_cost' => 1500,
        'passenger_last_name' => 'Тестов',
    ], [
        'bus_ticket_id' => 502,
        'bus_ticket_service_id' => 202,
        'bus_ticket_number' => 'BUS-502',
    ], [
        'bus_ticket_id' => 503,
        'bus_ticket_service_id' => 999,
        'bus_ticket_number' => 'BUS-503',
        'bus_ticket_description' => 'Рейс без подробностей',
    ]],
    'aeroexpress_tickets' => [[
        'aeroexpress_ticket_id' => 601,
        'aeroexpress_ticket_number' => 'AERO-601',
        'aeroexpress_ticket_trip_date' => '2026-10-20T12:00:00+03:00',
    ]],
]);
$busById = [];
foreach ($busItems as $item) {
    $busById[$item->externalId] = $item;
}
if (count($busById) !== 4
    || $busById['bus:501']->serviceType !== ServiceType::BUS
    || $busById['bus:501']->data['departure_at'] !== '2026-10-20T22:15:00+03:00'
    || $busById['bus:501']->data['arrival_at'] !== '2026-10-21T07:45:00+03:00'
    || $busById['bus:501']->data['service_id'] !== 101
    || $busById['bus:501']->data['departure_location'] !== 'Москва'
    || $busById['bus:501']->data['arrival_location'] !== 'Казань'
    || $busById['bus:501']->data['route'] !== 'Москва → Казань'
    || $busById['bus:501']->data['passenger'] !== 'Тестов'
    || $busById['bus:501']->data['ticket_number'] !== 'BUS-501'
    || $busById['bus:501']->data['amount'] !== 1500
    || $busById['bus:502']->data['departure_at'] !== '2026-10-21T00:00:00+03:00'
    || $busById['bus:502']->data['arrival_at'] !== '2026-10-21T06:30:00+03:00'
    || $busById['bus:502']->data['departure_location'] !== 'Автостанция Казань'
    || $busById['bus:503']->data['departure_at'] !== null
    || $busById['bus:503']->data['arrival_at'] !== null
    || $busById['bus:503']->data['route'] !== 'Рейс без подробностей'
    || $busById['aeroexpress:601']->data['departure_at'] !== '2026-10-20T12:00:00+03:00'
) {
    throw new RuntimeException('Trivio bus ticket/service journey mapping failed.');
}

$busMappedFields = (new \App\Sync\FieldMapper())->map($busById['bus:501']->fields(), [
    ['sourceField' => 'departure_at', 'targetField' => 'ufCrmDeparture', 'transform' => 'date'],
    ['sourceField' => 'arrival_at', 'targetField' => 'ufCrmArrival', 'transform' => 'date'],
]);
if (($busMappedFields['ufCrmDeparture'] ?? null) !== '2026-10-20T22:15:00+03:00'
    || ($busMappedFields['ufCrmArrival'] ?? null) !== '2026-10-21T07:45:00+03:00'
) {
    throw new RuntimeException('Trivio bus departure/arrival field mapping lost time or timezone.');
}

echo json_encode([
    'aviasales' => 'ok',
    'smartway' => 'ok',
    'trivio' => 'ok',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
