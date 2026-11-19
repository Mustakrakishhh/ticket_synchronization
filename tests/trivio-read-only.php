<?php

declare(strict_types=1);

use App\AppRepository;
use App\Bitrix24\CrmClientInterface;
use App\Crypto;
use App\MigrationRunner;
use App\Provider\ProviderFactoryInterface;
use App\Provider\TravelProviderInterface;
use App\Provider\Trivio\TrivioClient;
use App\Provider\Trivio\TrivioProvider;
use App\Sync\FieldMapper;
use App\Sync\SyncService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

// Isolated in-memory test: never loads production credentials or calls real APIs.
$pdo = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
(new MigrationRunner($pdo))->migrate();
$repository = new AppRepository($pdo, new Crypto(base64_encode(str_repeat('t', 32))));
$member = 'trivio-read-only-test';
$repository->saveProvider($member, 'trivio', ['token' => 'test-token'], true);
$repository->saveSettings($member, [
    'entityTypeId' => 177,
    'createItems' => true,
    'updateItems' => true,
    'attachDocuments' => false,
    'serviceRoutes' => ['air' => ['enabled' => true, 'categoryId' => 7]],
]);
$repository->saveMappings($member, 'trivio', [
    ['sourceField' => 'external_id', 'targetField' => 'ufCrmExternal', 'transform' => 'string'],
    ['sourceField' => 'amount', 'targetField' => 'opportunity', 'transform' => 'money'],
]);

$amount = 1000;
$requests = [];
$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$amount, &$requests): MockResponse {
    $body = (string) ($options['body'] ?? '');
    $requests[] = $body;
    if (!str_contains($body, '<int:getTicketTransactionInfo>') || str_contains($body, 'integrationFeedback')) {
        throw new RuntimeException('Unexpected Trivio request, including forbidden feedback.');
    }
    return new MockResponse('<?xml version="1.0" encoding="UTF-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" '
        . 'xmlns:ns1="https://corporate-api.trivio.ru/api/integration"><SOAP-ENV:Body>'
        . '<ns1:getTicketTransactionInfoResponse><return><ns1:result>true</ns1:result>'
        . '<ns1:transactions><ns1:tickettransactiondto>'
        . '<ns1:transaction_id>100</ns1:transaction_id><ns1:transaction_type>1</ns1:transaction_type>'
        . '<ns1:ticket_id>200</ns1:ticket_id><ns1:service_id>300</ns1:service_id>'
        . '<ns1:number>TEST-200</ns1:number><ns1:status>2</ns1:status>'
        . '<ns1:sum>' . $amount . '</ns1:sum>'
        . '</ns1:tickettransactiondto></ns1:transactions></return>'
        . '</ns1:getTicketTransactionInfoResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>');
});
$provider = new TrivioProvider(['token' => 'test-token'], new TrivioClient('test-token', $http));
$factory = new class($provider) implements ProviderFactoryInterface {
    public function __construct(private TravelProviderInterface $provider) {}
    public function create(string $provider, array $credentials): TravelProviderInterface { return $this->provider; }
};
$crm = new class implements CrmClientInterface {
    public int $adds = 0;
    public int $updates = 0;
    public bool $failUpdate = false;
    public array $fields = [];
    public function itemExists(int $entityTypeId, int $itemId): bool { return true; }
    public function findItemIdByField(int $entityTypeId, string $field, mixed $value): ?int { return null; }
    public function addItem(int $entityTypeId, array $fields): int
    {
        $this->adds++;
        $this->fields = $fields;
        return 900;
    }
    public function updateItem(int $entityTypeId, int $itemId, array $fields): void
    {
        if ($this->failUpdate) {
            throw new RuntimeException('Simulated CRM update failure');
        }
        $this->updates++;
        $this->fields = $fields;
    }
};
$sync = new SyncService($repository, $factory, new FieldMapper());
$first = $sync->run($member, 'trivio', $crm);
$repeat = $sync->run($member, 'trivio', $crm);
$amount = 1200;
$changed = $sync->run($member, 'trivio', $crm);
$amount = 1400;
$crm->failUpdate = true;
$failed = $sync->run($member, 'trivio', $crm);
$crm->failUpdate = false;
$retried = $sync->run($member, 'trivio', $crm);
$finalRepeat = $sync->run($member, 'trivio', $crm);

if ($first['state'] !== 'success' || $first['stats']['created'] !== 1
    || $repeat['state'] !== 'success' || $repeat['stats']['unchanged'] !== 1
    || $changed['state'] !== 'success' || $changed['stats']['updated'] !== 1
    || $failed['state'] !== 'partial' || $failed['stats']['errors'] !== 1
    || $retried['state'] !== 'success' || $retried['stats']['updated'] !== 1
    || $finalRepeat['state'] !== 'success' || $finalRepeat['stats']['unchanged'] !== 1
    || $crm->adds !== 1 || $crm->updates !== 2
    || ($crm->fields['opportunity'] ?? null) !== '1400.00|RUB'
    || count($requests) !== 6
) {
    throw new RuntimeException('Read-only Trivio synchronization failed: ' . json_encode(
        compact('first', 'repeat', 'changed', 'failed', 'retried', 'finalRepeat'),
        JSON_THROW_ON_ERROR,
    ));
}

echo "Trivio read-only sync: create, unchanged, update, error retry; no feedback: ok\n";
