<?php

declare(strict_types=1);

use App\AppRepository;
use App\Bitrix24\CrmClientInterface;
use App\Crypto;
use App\Domain\ServiceType;
use App\Domain\TravelItem;
use App\MigrationRunner;
use App\Provider\ProviderFactoryInterface;
use App\Provider\TravelProviderInterface;
use App\Provider\Trivio\TrivioClient;
use App\Provider\Trivio\TrivioJsonClient;
use App\Provider\Trivio\TrivioPricing;
use App\Provider\Trivio\TrivioProvider;
use App\Sync\ExpenseAggregator;
use App\Sync\ExpenseCatalog;
use App\Sync\FieldMapper;
use App\Sync\SyncService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// The authoritative final service price prevents both omitted and doubled fees.
$price = TrivioPricing::tickets([
    ['avia_ticket_id' => 1, 'avia_ticket_totalcost' => 3500, 'avia_ticket_fee' => 300],
], ['total_price' => 3800, 'avia_service_fee' => 300], 'avia_ticket_', 'avia_service_');
$check($price[1] == ['amount' => 3800.0, 'ticket_amount' => 3500.0, 'service_fee' => 300.0], 'Separate fee was omitted: ' . json_encode($price));
$included = TrivioPricing::tickets([
    ['avia_ticket_id' => 1, 'avia_ticket_totalcost' => 3800, 'avia_ticket_fee' => 300],
], ['total_price' => 3800, 'avia_service_fee' => 300], 'avia_ticket_', 'avia_service_');
$check($price === $included, 'Included fee was counted twice.');
$shared = TrivioPricing::tickets([
    ['avia_ticket_id' => 1, 'avia_ticket_totalcost' => 3500],
    ['avia_ticket_id' => 2, 'avia_ticket_totalcost' => 3500],
], ['total_price' => 7300, 'avia_service_fee' => 300], 'avia_ticket_', 'avia_service_');
$check($shared[1]['amount'] == 3650.0 && $shared[2]['amount'] == 3650.0, 'Shared commission was repeated per passenger.');
$component = TrivioPricing::tickets([
    ['avia_ticket_id' => 1, 'avia_ticket_totalcost' => 3500, 'avia_ticket_ticketfare' => 3000, 'avia_ticket_tickettax' => 500, 'avia_ticket_fee' => 300],
], [], 'avia_ticket_', 'avia_service_');
$check($component[1]['amount'] == 3800.0, 'Fare/tax/fee fallback failed.');
$multiTickets = [
    ['avia_ticket_id' => 3, 'avia_ticket_totalcost' => 100],
    ['avia_ticket_id' => 1, 'avia_ticket_totalcost' => 100],
    ['avia_ticket_id' => 2, 'avia_ticket_totalcost' => 100],
];
$multiService = ['total_price' => 310.01, 'avia_service_fee' => 10.01];
$rounding = TrivioPricing::tickets($multiTickets, $multiService, 'avia_ticket_', 'avia_service_');
$check($rounding === TrivioPricing::tickets(array_reverse($multiTickets), $multiService, 'avia_ticket_', 'avia_service_'), 'Rounding depends on API order.');
$check((int) round(array_sum(array_column($rounding, 'amount')) * 100) === 31001, 'Final kopeck was lost.');

$order = [
    'order' => ['order_id' => 111],
    'avia_services' => [
        ['service_id' => 10, 'avia_service_id' => 10, 'total_price' => 3800, 'avia_service_fee' => 300],
        ['service_id' => 20, 'avia_service_id' => 20, 'total_price' => 1100, 'avia_service_fee' => 100],
        ['service_id' => 30, 'avia_service_id' => 30, 'total_price' => 500],
    ],
    'avia_service_tickets' => [
        ['service_id' => 10, 'avia_ticket_id' => 100, 'avia_ticket_number' => '9990000000001', 'avia_ticket_totalcost' => 3500, 'avia_ticket_fee' => 300, 'avia_ticket_status' => 2],
        ['service_id' => 20, 'avia_ticket_id' => 200, 'avia_ticket_number' => 'EMD-200', 'avia_ticket_totalcost' => 1000, 'avia_ticket_fee' => 100, 'avia_ticket_status' => 2,
            'avia_ticket_description' => 'Багаж → Дополнительная услуга; Багаж; Авиабилет; 9990000000001; Москва - Казань'],
        ['service_id' => 30, 'avia_ticket_id' => 300, 'avia_ticket_number' => 'EMD-300', 'avia_ticket_totalcost' => 500, 'avia_ticket_status' => 2,
            'avia_ticket_description' => 'Выбор места → Дополнительная услуга; Выбор места; Авиабилет; 9990000000001; Москва - Казань'],
    ],
];
$documentRequests = [];
$failDocument = false;
$incremental = false;
$fileRequests = [];
$soap = static fn (string $body): string => '<?xml version="1.0" encoding="UTF-8"?>'
    . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:n="https://corporate-api.trivio.ru/api/integration"><s:Body>'
    . $body . '</s:Body></s:Envelope>';
$soapHttp = new MockHttpClient(static function (string $method, string $url, array $options) use ($soap, &$documentRequests, &$failDocument, &$incremental): MockResponse {
    $body = (string) ($options['body'] ?? '');
    if (str_contains($body, 'integrationFeedback')) {
        throw new RuntimeException('Feedback must remain disabled.');
    }
    if (str_contains($body, 'getTicketTransactionInfo')) {
        if ($incremental) {
            return new MockResponse($soap('<n:getTicketTransactionInfoResponse><return><n:result>true</n:result><n:transactions><n:tickettransactiondto>'
                . '<n:transaction_id>1</n:transaction_id><n:transaction_type>1</n:transaction_type><n:ticket_id>100</n:ticket_id><n:service_id>10</n:service_id>'
                . '<n:order_id>111</n:order_id><n:number>9990000000001</n:number><n:sum>3800</n:sum><n:status>2</n:status>'
                . '</n:tickettransactiondto></n:transactions></return></n:getTicketTransactionInfoResponse>'));
        }
        return new MockResponse($soap('<n:getTicketTransactionInfoResponse><return><n:result>true</n:result><n:transactions/></return></n:getTicketTransactionInfoResponse>'));
    }
    if (str_contains($body, 'printBlank')) {
        preg_match('/<ticketId>(\d+)<\/ticketId>/', $body, $match);
        $id = (int) $match[1];
        $documentRequests[] = $id;
        if ($failDocument && $id === 300) {
            return new MockResponse($soap('<n:printBlankResponse><return><n:result>false</n:result><n:error>Не найден билет по указанным параметрам</n:error></return></n:printBlankResponse>'));
        }
        return new MockResponse($soap('<n:printBlankResponse><return><n:result>true</n:result><n:extension>pdf</n:extension><n:data>'
            . base64_encode('%PDF-1.4 ancillary-' . $id) . '</n:data></return></n:printBlankResponse>'));
    }
    throw new RuntimeException('Unexpected SOAP method.');
});
$jsonHttp = new MockHttpClient(static function (string $method, string $url, array $options) use (&$order, &$incremental, &$fileRequests): MockResponse {
    $payload = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
    if ($payload['method'] === 'getFileContent') {
        if (!str_ends_with($url, '/api/file/json') || ($payload['params']['source'] ?? '') !== 'service') {
            throw new RuntimeException('Wrong File API endpoint or source.');
        }
        $fileRequests[] = $payload['params']['fileId'];
    }
    $result = match ($payload['method']) {
        'getSession' => ['result' => true, 'session' => ['session_id' => 'test-session']],
        'getOrders' => ['result' => true, 'orders' => $incremental ? [] : [['order_id' => 111]]],
        'getOrder' => ['result' => true, 'orderDetailed' => $order],
        'getFileContent' => ['result' => true, 'file' => [
            'name' => 'Багаж-' . $payload['params']['fileId'] . '.pdf',
            'mime' => 'application/pdf',
            'content' => base64_encode('%PDF-1.4 attached-' . $payload['params']['fileId']),
        ]],
        default => throw new RuntimeException('Unexpected Client API method.'),
    };
    return new MockResponse(json_encode(['result' => $result], JSON_THROW_ON_ERROR));
});
$provider = new TrivioProvider([], new TrivioClient('test', $soapHttp), new TrivioJsonClient('test', 'test', $jsonHttp));
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new MigrationRunner($pdo))->migrate();
$repository = new AppRepository($pdo, new Crypto(base64_encode(str_repeat('t', 32))));
$member = 'additional-test';
$repository->saveProvider($member, 'trivio', ['token' => 'test'], true);
$repository->saveSettings($member, [
    'entityTypeId' => 177, 'createItems' => true, 'updateItems' => true, 'attachDocuments' => true,
    'serviceRoutes' => ['air' => ['enabled' => true, 'categoryId' => 7]],
    'expenseSync' => ['enabled' => true, 'entityTypeId' => 299, 'categoryId' => 9, 'createItems' => true, 'updateItems' => true],
]);
$repository->saveMappings($member, 'trivio', [
    ['sourceField' => 'external_id', 'targetField' => 'ufExternal', 'transform' => 'string'],
    ['sourceField' => 'additional_service_types', 'targetField' => 'ufAdditionalTypes', 'transform' => 'string_multiple'],
    ['sourceField' => 'additional_service_files', 'targetField' => 'ufAdditionalFiles', 'transform' => 'file_multiple'],
    ['sourceField' => 'amount', 'targetField' => 'ufTotal', 'transform' => 'money'],
    ['sourceField' => 'service_fee', 'targetField' => 'ufFee', 'transform' => 'money'],
]);
$repository->saveMappings($member, ExpenseCatalog::MAPPING_SCOPE, [
    ['sourceField' => 'expense_external_id', 'targetField' => 'ufExternal', 'transform' => 'string'],
    ['sourceField' => 'amount', 'targetField' => 'ufTotal', 'transform' => 'money'],
]);
$factory = new class($provider) implements ProviderFactoryInterface {
    public function __construct(private TravelProviderInterface $provider) {}
    public function create(string $provider, array $credentials): TravelProviderInterface { return $this->provider; }
};
$crm = new class implements CrmClientInterface {
    public array $adds = [];
    public array $updates = [];
    public function itemExists(int $entityTypeId, int $itemId): bool { return true; }
    public function findItemIdByField(int $entityTypeId, string $field, mixed $value): ?int { return null; }
    public function addItem(int $entityTypeId, array $fields): int { $this->adds[$entityTypeId][] = $fields; return $entityTypeId; }
    public function updateItem(int $entityTypeId, int $itemId, array $fields): void { $this->updates[$entityTypeId][] = $fields; }
};
$sync = new SyncService($repository, $factory, new FieldMapper());
$first = $sync->run($member, 'trivio', $crm);
$fields = $crm->adds[177][0] ?? [];
$check($first['state'] === 'success' && $first['stats']['created'] === 1, 'Ticket failed to synchronize: ' . json_encode($first));
$check(($fields['ufTotal'] ?? '') === '5400.00|RUB', 'Ticket + extras + commission total is wrong.');
$check(($crm->adds[299][0]['ufTotal'] ?? '') === '5400.00|RUB', 'Expense and ticket totals differ or extras counted twice.');
$check(($fields['ufAdditionalTypes'] ?? []) === ['Багаж', 'Выбор места'], 'Types must be an array, not a JSON string.');
$check(count($fields['ufAdditionalFiles'] ?? []) === 2 && $documentRequests === [200, 300], 'Ancillary PDFs were not fetched using their own IDs.');
$second = $sync->run($member, 'trivio', $crm);
$check($second['stats']['unchanged'] === 1 && count($documentRequests) === 2, 'Unchanged run redownloads/reuploads documents.');
$incremental = true;
$queueOnly = $sync->run($member, 'trivio', $crm);
$check($queueOnly['stats']['unchanged'] === 1 && $queueOnly['stats']['expenseUnchanged'] === 1, 'Incremental queue dropped previously known extras or commission.');
$incremental = false;

// A changed attachment must retry without deleting the previously uploaded bundle.
$order['avia_service_tickets'][2]['avia_ticket_description'] .= '; изменено';
$failDocument = true;
$retry = $sync->run($member, 'trivio', $crm);
$last = $crm->updates[177][array_key_last($crm->updates[177])] ?? [];
$check($retry['stats']['documentsUnavailable'] === 1 && !array_key_exists('ufAdditionalFiles', $last), 'Incomplete response replaced previously uploaded files.');
$failDocument = false;
$recovered = $sync->run($member, 'trivio', $crm);
$check($recovered['state'] === 'success', 'Document retry did not recover.');

// A full order response can remove extras and must clear the managed fields.
$order['avia_service_tickets'] = [$order['avia_service_tickets'][0]];
$cleared = $sync->run($member, 'trivio', $crm);
$last = $crm->updates[177][array_key_last($crm->updates[177])];
$check(($last['ufAdditionalTypes'] ?? null) === [] && ($last['ufAdditionalFiles'] ?? null) === [], 'Removed extras did not clear multiple fields.');
$check(($last['ufTotal'] ?? '') === '3800.00|RUB' && $cleared['state'] === 'success', 'Base total changed after removal of extras.');

// Strong parent references must never attach to a different ticket or currency.
$base = new TravelItem('trivio', 'air:1', ServiceType::AIR, ['order_id' => 1, 'ticket_number' => '9990000000001', 'amount' => 100, 'currency' => 'RUB']);
$other = new TravelItem('trivio', 'air:2', ServiceType::ADDITIONAL_SERVICE, ['order_id' => 1, 'base_ticket_number' => '9999999999999', 'amount' => 200, 'currency' => 'RUB']);
$group = (new ExpenseAggregator())->group('trivio', [$base, $other]);
$check($group['unmatchedAdditional'] === 1, 'Unrelated extra was attached through order fallback.');

// Bus baggage is an arbitrary service without a ticketId: its PDF is in service_files.
$order = [
    'order' => ['order_id' => 222],
    'bus_services' => [['service_id' => 600, 'bus_service_id' => 60, 'total_price' => 1000]],
    'bus_tickets' => [[
        'service_id' => 600, 'bus_ticket_service_id' => 60, 'bus_ticket_id' => 601,
        'bus_ticket_number' => '00576100000001', 'bus_ticket_total_cost' => 1000, 'bus_ticket_status' => 2,
    ]],
    'arbitrary_services' => [[
        'service_id' => 700, 'arbitrary_service_id' => 70, 'title' => 'Багаж',
        'description' => 'Дополнительная услуга; Багаж; Автобус; 00576100000002',
        'service_current_status' => 2, 'total_price' => 100,
    ]],
    'service_files' => [
        ['service_file_id' => 701, 'service_file_service_id' => 700, 'service_file_name' => 'Багаж-701.pdf', 'service_file_mark_to_delete' => 0],
        ['service_file_id' => 702, 'service_file_service_id' => 700, 'service_file_name' => 'Багаж-702.pdf', 'service_file_mark_to_delete' => 0],
        ['service_file_id' => 703, 'service_file_service_id' => 999, 'service_file_name' => 'Другой файл.pdf', 'service_file_mark_to_delete' => 0],
        ['service_file_id' => 704, 'service_file_service_id' => 700, 'service_file_name' => 'Удалённый.pdf', 'service_file_mark_to_delete' => 1],
    ],
];
$settings = $repository->settings($member);
$settings['serviceRoutes']['bus'] = ['enabled' => true, 'categoryId' => 7];
$repository->saveSettings($member, $settings);
$baggage = $sync->run($member, 'trivio', $crm);
$busFields = $crm->adds[177][array_key_last($crm->adds[177])];
$check($baggage['state'] === 'success' && $baggage['stats']['created'] === 1, 'Bus baggage attachment synchronization failed: ' . json_encode($baggage));
$check($fileRequests === [701, 702], 'Must fetch only non-deleted files of the exact baggage service.');
$check(($busFields['ufAdditionalTypes'] ?? []) === ['Багаж'] && count($busFields['ufAdditionalFiles'] ?? []) === 2, 'Attached baggage PDFs did not reach the ticket card.');
$check(($busFields['ufTotal'] ?? '') === '1100.00|RUB', 'Attached arbitrary baggage cost is missing.');
$repeatedBaggage = $sync->run($member, 'trivio', $crm);
$check($repeatedBaggage['stats']['unchanged'] === 1 && $fileRequests === [701, 702], 'Repeated bus sync redownloaded or duplicated attachments.');
$order['service_files'][0]['service_file_date'] = '2026-10-28T12:00:00+03:00';
$sync->run($member, 'trivio', $crm);
$check($fileRequests === [701, 702, 701, 702], 'Attachment revision did not trigger a refresh.');

$invalidClient = new TrivioJsonClient('test', 'test', new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
    $request = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
    $result = $request['method'] === 'getSession'
        ? ['result' => true, 'session' => ['session_id' => 'test-session']]
        : ['result' => true, 'file' => ['name' => 'ticket.pdf', 'mime' => 'application/pdf', 'content' => base64_encode('<html>Login required</html>')]];
    return new MockResponse(json_encode(['result' => $result], JSON_THROW_ON_ERROR));
}));
$invalidRejected = false;
try {
    $invalidClient->downloadServiceFile(701);
} catch (RuntimeException $exception) {
    $invalidRejected = str_contains($exception->getMessage(), 'не является поддерживаемым');
}
$check($invalidRejected, 'An HTML/login page must never be uploaded as a ticket PDF.');

echo "Additional services: prices, fees, multiple values, PDFs, retries, removal, expense totals: ok\n";
