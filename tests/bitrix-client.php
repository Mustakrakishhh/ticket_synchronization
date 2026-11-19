<?php

declare(strict_types=1);

use App\Bitrix24\Bitrix24Client;
use Bitrix24\SDK\Core\Batch;
use Bitrix24\SDK\Core\BulkItemsReader\BulkItemsReaderBuilder;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Endpoints;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\ServiceBuilder;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$buildClient = static function (array $responses, ?callable $inspect = null): Bitrix24Client {
    $responseQueue = array_map(
        static function (array $payload): MockResponse {
            if (array_key_exists('result', $payload)) {
                $payload['time'] = [
                    'start' => 1.0,
                    'finish' => 1.01,
                    'duration' => 0.01,
                    'processing' => 0.005,
                    'operating' => 0.0,
                    'date_start' => '2026-08-17T10:00:00+00:00',
                    'date_finish' => '2026-08-17T10:00:00+00:00',
                ];
            }
            return new MockResponse(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        },
        $responses,
    );
    $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responseQueue, $inspect): MockResponse {
        if ($inspect !== null) {
            $inspect($url, $options);
        }
        return array_shift($responseQueue) ?? throw new RuntimeException('Unexpected REST call.');
    });
    $logger = new NullLogger();
    $credentials = Credentials::createFromOAuth(
        new AuthToken('access-token', 'refresh-token', time() + 3600, 3600),
        new ApplicationProfile(
            'client-id',
            'client-secret',
            Scope::initFromString('crm,im,user_basic,userfieldconfig'),
        ),
        new Endpoints('https://example.bitrix24.ru/rest/', 'https://oauth.bitrix24.tech/'),
    );
    $core = (new CoreBuilder())
        ->withEventDispatcher(new EventDispatcher())
        ->withLogger($logger)
        ->withHttpClient($http)
        ->withCredentials($credentials)
        ->build();
    $batch = new Batch($core, $logger);
    return new Bitrix24Client(new ServiceBuilder(
        $core,
        $batch,
        (new BulkItemsReaderBuilder($core, $batch, $logger))->build(),
        $logger,
    ));
};

$methodError = [
    'error' => 'METHOD_NOT_FOUND',
    'error_description' => 'Method not found!',
];
$client = $buildClient([
    $methodError,
    ['result' => ['isExisting' => true, 'isAvailable' => true]],
    ['result' => ['item' => ['id' => 501]]],
]);
$assert($client->addItem(177, ['title' => 'Retry test']) === 501, 'Safe METHOD_NOT_FOUND retry did not create the item.');

$unavailable = $buildClient([
    $methodError,
    ['result' => ['isExisting' => true, 'isAvailable' => false]],
]);
$blocked = false;
try {
    $unavailable->addItem(177, ['title' => 'Unavailable test']);
} catch (RuntimeException $exception) {
    $blocked = str_contains($exception->getMessage(), 'OAuth-токену');
}
$assert($blocked, 'Unavailable crm.item.add must produce a clear OAuth capability error.');

$finder = $buildClient([
    ['result' => ['items' => [['id' => 777, 'ufCrmExternal' => 'air:4105208']]]],
]);
$assert(
    $finder->findItemIdByField(177, 'ufCrmExternal', 'air:4105208') === 777,
    'Created item recovery by external ID did not return the Bitrix24 item ID.',
);

$createdFieldRequest = null;
$fieldName = 'UF_CRM_177_TRAVEL_SYNC_ADDITIONAL_SERVICE_TYPES';
$fieldClient = $buildClient([
    ['result' => ['fields' => []]],
    ['result' => ['field' => ['fieldName' => $fieldName, 'multiple' => 'Y']]],
], static function (string $url, array $options) use (&$createdFieldRequest): void {
    if (str_contains($url, 'userfieldconfig.add')) {
        $body = $options['body'] ?? '';
        $createdFieldRequest = is_array($body) ? $body : json_decode((string) $body, true);
        if (!is_array($createdFieldRequest)) {
            parse_str((string) $body, $createdFieldRequest);
        }
    }
});
$fieldClient->ensureField(177, 'additional_service_types', \App\Provider\ProviderCatalog::fieldDefinition('additional_service_types'));
$assert(($createdFieldRequest['field']['multiple'] ?? null) === 'Y', 'Additional service types must create a multiple field.');
$assert(($createdFieldRequest['field']['userTypeId'] ?? null) === 'string', 'Additional service types must use a string field.');

echo "Bitrix client resilience checks passed.\n";
