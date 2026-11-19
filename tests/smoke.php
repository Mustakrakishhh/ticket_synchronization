<?php

declare(strict_types=1);

use App\Application;
use App\Bitrix24\BitrixMetadataService;
use App\Diagnostics\AppLogger;
use App\Domain\ServiceType;
use App\Provider\ProviderCatalog;
use App\Provider\Trivio\TrivioNormalizer;
use App\Sync\FieldMapper;

$defaults = [
    'APP_ENV' => 'test',
    'APP_PUBLIC_URL' => 'https://example.test',
    'APP_SESSION_SECRET' => 'test-session-secret-with-at-least-32-characters',
    'APP_ENCRYPTION_KEY' => 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    'BITRIX24_CLIENT_ID' => 'test-client',
    'BITRIX24_CLIENT_SECRET' => 'test-secret',
    'DATABASE_DSN' => 'sqlite::memory:',
];
foreach ($defaults as $name => $value) {
    if (getenv($name) === false && !isset($_ENV[$name])) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }
}

/** @var Application $app */
$app = require __DIR__ . '/bootstrap.php';

$providers = ProviderCatalog::all();
$providerFields = [];
foreach ($providers as $providerDefinition) {
    $providerFields[$providerDefinition['code']] = $providerDefinition['fields'];
}
if (!in_array('ticket_file', $providerFields['aviasales'] ?? [], true)
    || !in_array('ticket_file', $providerFields['trivio'] ?? [], true)
    || !in_array('ticket_file', $providerFields['smartway'] ?? [], true)
) {
    throw new RuntimeException('Поле ticket_file должно быть доступно адаптерам Aviasales, Trivio и Smartway.');
}
$commonFields = array_values(array_intersect(
    $providerFields['aviasales'] ?? [],
    $providerFields['trivio'] ?? [],
    $providerFields['smartway'] ?? [],
));
if (!in_array('external_id', $commonFields, true)
    || !in_array('ticket_file', $commonFields, true)
    || in_array('buyer', $commonFields, true)
    || !in_array('buyer', $providerFields['aviasales'] ?? [], true)
    || in_array('buyer', $providerFields['trivio'] ?? [], true)
    || in_array('buyer', $providerFields['smartway'] ?? [], true)
) {
    throw new RuntimeException('Каталог общих и специфичных полей провайдеров сформирован неверно.');
}
$externalIdDefinition = ProviderCatalog::fieldDefinition('external_id');
if (($externalIdDefinition['label'] ?? null) !== 'Уникальный ID записи'
    || ($externalIdDefinition['type'] ?? null) !== 'string'
) {
    throw new RuntimeException('Русские названия или код пользовательского поля Bitrix24 сформированы неверно.');
}
$trivioCredentials = ProviderCatalog::validateCredentials('trivio', [
    'token' => 'integration-token',
    'login' => 'integration@example.test',
    'password' => 'client-password',
]);
if (($trivioCredentials['token'] ?? null) !== 'integration-token'
    || ($trivioCredentials['login'] ?? null) !== 'integration@example.test'
    || ($trivioCredentials['password'] ?? null) !== 'client-password'
    || count($trivioCredentials) !== 3
) {
    throw new RuntimeException('Проверка credentials Trivio не пройдена.');
}
$healthMember = 'provider-health-test';
$app->repository->saveProvider($healthMember, 'aviasales', [
    'api_key' => 'test-key',
    'company_id' => 'test-company',
], true);
$app->repository->markProviderChecked($healthMember, 'aviasales', null);
$verifiedBeforeError = $app->repository->providers($healthMember)[0]['verified_at'] ?? null;
$app->repository->markProviderChecked($healthMember, 'aviasales', 'Temporary provider error');
$healthAfterError = $app->repository->providers($healthMember)[0] ?? [];
if ($verifiedBeforeError === null
    || ($healthAfterError['verified_at'] ?? null) !== $verifiedBeforeError
    || ($healthAfterError['last_error'] ?? null) !== 'Temporary provider error'
) {
    throw new RuntimeException('Статус ранее проверенного подключения не сохраняется после временной ошибки.');
}
$setupMember = 'setup-complete-test';
$app->repository->saveProvider($setupMember, 'trivio', ['token' => 'test-token'], true);
$app->repository->saveSettings($setupMember, [
    'entityTypeId' => 1258,
    'enabled' => false,
    'serviceRoutes' => [
        ServiceType::AIR => ['enabled' => true, 'categoryId' => 3, 'stageId' => 'DT1258_3:NEW'],
    ],
]);
if ($app->repository->setupComplete($setupMember)) {
    throw new RuntimeException('Мастер не должен считаться завершённым без сопоставления external_id.');
}
$app->repository->saveMappings($setupMember, 'trivio', [[
    'sourceField' => 'external_id',
    'targetField' => 'ufCrmExternalId',
    'transform' => 'string',
]]);
if (!$app->repository->setupComplete($setupMember)) {
    throw new RuntimeException('Завершённая настройка мастера не распознана.');
}
$driver = (string) $app->database->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$tables = $driver === 'sqlite'
    ? $app->database->pdo
        ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN)
    : [];

$expectedTables = [
    'audit_events', 'bitrix_metadata_cache', 'field_mappings', 'notification_outbox', 'provider_connections', 'queue_guards', 'sync_cursors', 'sync_items',
    'sync_locks', 'sync_runs', 'tenant_settings', 'tenant_users', 'tenants', 'schema_migrations',
];
if ($driver === 'sqlite' && array_diff($expectedTables, $tables) !== []) {
    throw new RuntimeException('Не созданы обязательные таблицы БД.');
}
$runInsert = $app->database->pdo->prepare(
    'INSERT INTO sync_runs (id, member_id, provider, state, stats_json, error_text, started_at, finished_at)
     VALUES (:id, :member, :provider, :state, :stats, NULL, :started, :finished)'
);
for ($index = 0; $index < 25; $index++) {
    $startedAt = gmdate(DATE_ATOM, 1_700_000_000 + $index);
    $runInsert->execute([
        'id' => "limit-run-{$index}",
        'member' => 'limit-member',
        'provider' => 'trivio',
        'state' => 'success',
        'stats' => '{}',
        'started' => $startedAt,
        'finished' => $startedAt,
    ]);
}
$limitedRuns = $app->repository->syncRuns('limit-member', 20);
if (count($limitedRuns) !== 20 || ($limitedRuns[0]['id'] ?? null) !== 'limit-run-24') {
    throw new RuntimeException('Журнал синхронизации не ограничивается на уровне SQL.');
}
$metadataLog = dirname(__DIR__) . '/var/metadata-' . bin2hex(random_bytes(8)) . '.log';
$metadata = new BitrixMetadataService($app->repository, new AppLogger($metadataLog));
$metadataLoads = 0;
$firstMetadata = $metadata->remember('metadata-member', 'fields:177', 600, static function () use (&$metadataLoads): array {
    $metadataLoads++;
    return [['code' => 'title']];
});
$cachedMetadata = $metadata->remember('metadata-member', 'fields:177', 600, static function () use (&$metadataLoads): array {
    $metadataLoads++;
    return [['code' => 'unexpected']];
});
$app->database->pdo->exec("UPDATE bitrix_metadata_cache SET expires_at = '2000-01-01T00:00:00+00:00' WHERE member_id = 'metadata-member'");
$staleMetadata = $metadata->remember('metadata-member', 'fields:177', 600, static function () use (&$metadataLoads): array {
    $metadataLoads++;
    throw new RuntimeException('Temporary Bitrix24 metadata error');
});
$metadataLogContents = is_file($metadataLog) ? (string) file_get_contents($metadataLog) : '';
@unlink($metadataLog);
if ($firstMetadata !== [['code' => 'title']]
    || $cachedMetadata !== $firstMetadata
    || $staleMetadata !== $firstMetadata
    || $metadataLoads !== 2
    || !str_contains($metadataLogContents, 'ui.metadata.completed')
    || !str_contains($metadataLogContents, '"stale":true')
) {
    throw new RuntimeException('Серверный кеш метаданных Bitrix24 работает неверно.');
}
$syncItemColumns = $driver === 'sqlite'
    ? $app->database->pdo->query('PRAGMA table_info(sync_items)')->fetchAll()
    : [];
if ($driver === 'sqlite' && !in_array('entity_type_id', array_column($syncItemColumns, 'name'), true)) {
    throw new RuntimeException('Migration did not scope sync_items by smart-process entity type.');
}

$normalized = (new TrivioNormalizer())->normalize([
    '_currency' => 'RUB',
    'order' => ['order_id' => 101, 'order_company_name' => 'Test', 'order_date' => '2026-01-01'],
    'avia_services' => [[
        'service_id' => 501,
        'avia_service_route' => 'Москва — Казань',
        'avia_service_route_departure' => '',
        'avia_service_route_arrival' => '',
        'avia_service_pnr' => 'ABC123',
        'service_current_status' => 2,
        'flight_info' => [
            'directions' => [
                'segments' => [
                    'segment_departure_city_name' => 'Ижевск',
                    'segment_arrival_city_name' => 'Москва',
                    'segment_departure_time' => '2026-08-25T20:30:00+03:00',
                    'segment_arrival_time' => '2026-08-25T21:40:00+03:00',
                ],
            ],
        ],
    ]],
    'avia_service_tickets' => [[
        'service_id' => 501,
        'avia_ticket_id' => 9001,
        'avia_ticket_number' => '5550001',
        'avia_ticket_totalcost' => 12500.50,
        'avia_ticket_desc_departure' => '',
        'avia_ticket_desc_arrival' => '',
        'avia_ticket_desc_departure_date' => '25.08.2026',
        'avia_ticket_desc_arrival_date' => '25.08.2026',
        'passenger_first_name' => 'Иван',
        'passenger_last_name' => 'Иванов',
        'avia_ticket_status' => 2,
    ]],
    'hotel_multi_services' => [[
        'service_id' => 601,
        'hotel_service_id' => 6001,
        'hotel_service_check_in_date' => '2026-01-02',
        'hotel_service_check_out_date' => '2026-01-04',
        'hotel_service_price' => 8000,
        'service_current_status' => 2,
        'hotel_multi_info' => ['hotel_name' => 'Тестовый хостел', 'hotel_city' => 'Казань'],
    ]],
]);
if (count($normalized) !== 2
    || $normalized[0]->serviceType !== ServiceType::AIR
    || $normalized[1]->serviceType !== ServiceType::ACCOMMODATION
    || ($normalized[0]->fields()['transport_type'] ?? null) !== 'Самолёт'
    || ($normalized[0]->data['departure_location'] ?? null) !== 'Ижевск'
    || ($normalized[0]->data['arrival_location'] ?? null) !== 'Москва'
    || ($normalized[0]->data['departure_at'] ?? null) !== '2026-08-25T20:30:00+03:00'
    || ($normalized[0]->data['arrival_at'] ?? null) !== '2026-08-25T21:40:00+03:00'
    || ($normalized[0]->data['service_url'] ?? null) !== 'https://corporate.trivio.ru/order/show?order_id=101#data'
    || !array_key_exists('transport_type', $normalized[1]->fields())
    || $normalized[1]->fields()['transport_type'] !== null
    || ($normalized[1]->data['check_in'] ?? null) !== '2026-01-02'
    || ($normalized[1]->data['check_out'] ?? null) !== '2026-01-04'
) {
    throw new RuntimeException('Нормализация Trivio не прошла smoke-тест.');
}

$mapped = (new FieldMapper())->map($normalized[0]->fields(), [
    ['sourceField' => 'external_id', 'targetField' => 'ufCrmExternal', 'transform' => 'string'],
    ['sourceField' => 'amount', 'targetField' => 'opportunity', 'transform' => 'money'],
]);
if (($mapped['ufCrmExternal'] ?? '') !== 'air:9001' || ($mapped['opportunity'] ?? '') !== '12500.50|RUB') {
    throw new RuntimeException('Сопоставление полей не прошло smoke-тест.');
}

$runId = $app->repository->startSyncRun('test-member', 'trivio', 'test');
$lockWorked = false;
try {
    $app->repository->startSyncRun('test-member', 'trivio', 'test');
} catch (RuntimeException) {
    $lockWorked = true;
}
if (!$lockWorked) {
    throw new RuntimeException('Блокировка параллельного запуска не сработала.');
}
$app->repository->finishSyncRun($runId, 'success', ['received' => 0]);
if ($app->repository->isSyncDue('test-member', 'trivio', 30)) {
    throw new RuntimeException('Интервал планировщика не учитывается.');
}

$queuedRunId = $app->repository->enqueueSyncRun('queue-member', 'smartway', 'manual');
$duplicateRunId = $app->repository->enqueueSyncRun('queue-member', 'smartway', 'manual');
$queuedRun = $app->repository->syncRun($queuedRunId);
$nextRun = $app->repository->nextQueuedSyncRun('queue-member', 'smartway');
if ($queuedRunId !== $duplicateRunId
    || ($queuedRun['state'] ?? null) !== 'queued'
    || ($nextRun['id'] ?? null) !== $queuedRunId
    || !$app->repository->claimSyncRun($queuedRunId)
    || $app->repository->claimSyncRun($queuedRunId)
) {
    throw new RuntimeException('Queue acquisition test failed.');
}
$app->repository->heartbeatSyncRun($queuedRunId, ['trigger' => 'manual', 'received' => 10]);
$runningRun = $app->repository->syncRun($queuedRunId);
if (($runningRun['state'] ?? null) !== 'running'
    || ($runningRun['stats']['received'] ?? null) !== 10
) {
    throw new RuntimeException('Queue heartbeat test failed.');
}
$app->repository->finishSyncRun($queuedRunId, 'success', $runningRun['stats']);

$staleRunId = $app->repository->startSyncRun('stale-member', 'aviasales', 'manual');
$staleLock = $app->database->pdo->prepare('UPDATE sync_locks SET locked_at = :locked WHERE run_id = :id');
$staleLock->execute(['locked' => gmdate(DATE_ATOM, time() - 601), 'id' => $staleRunId]);
$replacementRunId = $app->repository->enqueueSyncRun('stale-member', 'aviasales', 'manual');
if ($replacementRunId === $staleRunId
    || ($app->repository->syncRun($staleRunId)['state'] ?? null) !== 'failed'
    || ($app->repository->syncRun($replacementRunId)['state'] ?? null) !== 'queued'
) {
    throw new RuntimeException('Stale worker recovery test failed.');
}
if (!$app->repository->claimSyncRun($replacementRunId)) {
    throw new RuntimeException('Replacement queue job could not be claimed.');
}
$app->repository->finishSyncRun($replacementRunId, 'success', ['trigger' => 'manual']);
$failedRunId = $app->repository->startSyncRun('retry-member', 'trivio', 'schedule');
$app->repository->finishSyncRun($failedRunId, 'failed', ['trigger' => 'schedule'], 'temporary error');
$failedStartedAt = $app->database->pdo->prepare('UPDATE sync_runs SET started_at = :started WHERE id = :id');
$failedStartedAt->execute(['started' => gmdate(DATE_ATOM, time() - 301), 'id' => $failedRunId]);
if ($app->repository->isSyncDue('retry-member', 'trivio', 30)) {
    throw new RuntimeException('Failed scheduled job must respect the configured interval.');
}
$failedStartedAt->execute(['started' => gmdate(DATE_ATOM, time() - 1801), 'id' => $failedRunId]);
if (!$app->repository->isSyncDue('retry-member', 'trivio', 30)) {
    throw new RuntimeException('Failed scheduled job must become eligible after the configured interval.');
}

$cycleMember = 'cycle-member';
if (!$app->repository->isSyncCycleDue($cycleMember, ['aviasales', 'trivio'], 15)) {
    throw new RuntimeException('A new provider cycle must be due.');
}
$cycleRunId = $app->repository->startSyncRun($cycleMember, 'aviasales', 'schedule');
$app->repository->finishSyncRun($cycleRunId, 'success', ['trigger' => 'schedule']);
if ($app->repository->isSyncCycleDue($cycleMember, ['aviasales', 'trivio'], 15)) {
    throw new RuntimeException('Provider cycle must wait until every provider is due.');
}
$cycleStartedAt = $app->database->pdo->prepare('UPDATE sync_runs SET started_at = :started WHERE id = :id');
$cycleStartedAt->execute(['started' => gmdate(DATE_ATOM, time() - 901), 'id' => $cycleRunId]);
if (!$app->repository->isSyncCycleDue($cycleMember, ['aviasales', 'trivio'], 15)) {
    throw new RuntimeException('Overdue providers must start together in one cycle.');
}

echo json_encode([
    'distribution' => $app->config->distribution,
    'providers' => array_column($providers, 'code'),
    'databaseDriver' => $driver,
    'databaseTables' => $tables,
    'normalizedItems' => count($normalized),
    'publicUrl' => $app->config->publicUrl,
    'encryptionReady' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
