<?php

declare(strict_types=1);

use App\Application;
use App\Diagnostics\AppLogger;
use App\Domain\ServiceType;
use App\Bitrix24\BitrixMetadataService;
use App\Provider\ProviderCatalog;
use App\Provider\ProviderFactory;
use App\Sync\ExpenseCatalog;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$requestId = bin2hex(random_bytes(8));
$requestStarted = microtime(true);
$requestCompleted = false;
$logger = new AppLogger(dirname(__DIR__) . '/var/app.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Request-ID: ' . $requestId);

register_shutdown_function(static function () use (
    &$requestCompleted,
    $logger,
    $requestId,
    $requestStarted,
): void {
    if ($requestCompleted) {
        return;
    }
    $error = error_get_last();
    $logger->write('api.aborted', [
        'requestId' => $requestId,
        'action' => (string) ($_GET['action'] ?? 'bootstrap'),
        'durationMs' => (int) round((microtime(true) - $requestStarted) * 1000),
        'memoryPeakBytes' => memory_get_peak_usage(true),
        'errorType' => $error['type'] ?? null,
        'error' => $error['message'] ?? 'Request ended before a response was completed.',
    ]);
});

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $session = $app->sessions->requireValid(!in_array($method, ['GET', 'HEAD'], true));
    if (empty($session['admin'])) {
        throw new RuntimeException('Настройки Travel Sync доступны только администратору Bitrix24.', 403);
    }
    $memberId = (string) $session['member'];
    $action = (string) ($_GET['action'] ?? 'bootstrap');
    $metadataActions = [
        'smart-processes',
        'smart-process-fields',
        'smart-process-categories',
        'smart-process-stages',
        'bitrix-users',
    ];
    if (in_array($action, $metadataActions, true)) {
        $logger->write('api.started', [
            'requestId' => $requestId,
            'action' => $action,
            'entityTypeId' => (int) ($_GET['entityTypeId'] ?? 0),
        ]);
    }
    $metadata = new BitrixMetadataService($app->repository, $logger);
    $forceMetadataRefresh = (string) ($_GET['refresh'] ?? '') === '1';
    $body = [];
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    }

    $data = match ($action) {
        'bootstrap' => (static function () use ($app, $memberId): array {
            $catalog = ProviderCatalog::all();
            $settings = $app->repository->settings($memberId);
            $entityTypeId = (int) ($settings['entityTypeId'] ?? 0);
            $expenseSync = is_array($settings['expenseSync'] ?? null) ? $settings['expenseSync'] : [];
            $expenseEntityTypeId = (int) ($expenseSync['entityTypeId'] ?? 0);
            $cachedValue = static function (string $key) use ($app, $memberId): array {
                $cached = $app->repository->bitrixMetadata($memberId, $key);
                return is_array($cached['value'] ?? null) ? $cached['value'] : [];
            };
            $mappings = [];
            foreach ($catalog as $provider) {
                $code = (string) ($provider['code'] ?? '');
                if ($code !== '') {
                    $mappings[$code] = $app->repository->mappings($memberId, $code);
                }
            }
            $stages = [];
            foreach (is_array($settings['serviceRoutes'] ?? null) ? $settings['serviceRoutes'] : [] as $route) {
                $categoryId = is_array($route) ? (int) ($route['categoryId'] ?? -1) : -1;
                if ($entityTypeId > 0 && $categoryId >= 0 && !array_key_exists((string) $categoryId, $stages)) {
                    $stages[(string) $categoryId] = $cachedValue("stages:{$entityTypeId}:{$categoryId}");
                }
            }
            $expenseCategoryId = (int) ($expenseSync['categoryId'] ?? -1);
            $usersCache = $app->repository->bitrixMetadata($memberId, 'users');

            return [
                'catalog' => $catalog,
                'connections' => $app->repository->providers($memberId),
                'settings' => $settings,
                'setupComplete' => $app->repository->setupComplete($memberId),
                'serviceTypes' => ServiceType::all(),
                'processes' => $cachedValue('processes'),
                'fields' => $entityTypeId > 0 ? $cachedValue("fields:{$entityTypeId}") : [],
                'categories' => $entityTypeId > 0 ? $cachedValue("categories:{$entityTypeId}") : [],
                'stages' => $stages,
                'mappings' => $mappings,
                'expenseCatalog' => [
                    'identityField' => ExpenseCatalog::IDENTITY_FIELD,
                    'fields' => ExpenseCatalog::fields(),
                    'fieldDefinitions' => ExpenseCatalog::definitions(),
                ],
                'expenseMappings' => $app->repository->mappings($memberId, ExpenseCatalog::MAPPING_SCOPE),
                'expenseMappingsLoaded' => true,
                'expenseFields' => $expenseEntityTypeId > 0 ? $cachedValue("fields:{$expenseEntityTypeId}") : [],
                'expenseCategories' => $expenseEntityTypeId > 0 ? $cachedValue("categories:{$expenseEntityTypeId}") : [],
                'expenseStages' => $expenseEntityTypeId > 0 && $expenseCategoryId >= 0
                    ? $cachedValue("stages:{$expenseEntityTypeId}:{$expenseCategoryId}")
                    : [],
                'users' => is_array($usersCache['value'] ?? null) ? $usersCache['value'] : [],
                'usersLoaded' => $usersCache !== null,
                // The journal is loaded only when step 6 is opened. Keeping this query out
                // of bootstrap makes every app opening independent of sync_runs growth.
                'syncRuns' => [],
                'support' => [
                    'email' => $app->config->supportEmail,
                    'privacyUrl' => $app->config->privacyUrl,
                    'retentionDays' => $app->config->retentionDays,
                    'version' => '0.8.0',
                ],
            ];
        })(),
        'providers.save' => (static function () use ($app, $memberId, $body): array {
            $provider = (string) ($body['provider'] ?? '');
            $credentials = ProviderCatalog::validateCredentials(
                $provider,
                is_array($body['credentials'] ?? null) ? $body['credentials'] : [],
            );
            $app->repository->saveProvider($memberId, $provider, $credentials, !empty($body['enabled']));
            return ['saved' => true, 'provider' => $provider];
        })(),
        'providers.test' => (static function () use ($app, $memberId, $body): array {
            $provider = (string) ($body['provider'] ?? '');
            ProviderCatalog::get($provider);
            try {
                (new ProviderFactory())
                    ->create($provider, $app->repository->providerCredentials($memberId, $provider))
                    ->testConnection();
                $app->repository->markProviderChecked($memberId, $provider, null);
                return ['connected' => true, 'provider' => $provider];
            } catch (Throwable $exception) {
                $app->repository->markProviderChecked($memberId, $provider, $exception->getMessage());
                throw $exception;
            }
        })(),
        'smart-processes' => $metadata->remember(
            $memberId,
            'processes',
            1800,
            fn (): array => $app->bitrix($memberId)->smartProcesses(),
            $forceMetadataRefresh,
        ),
        'smart-process-fields' => (static function () use ($app, $memberId, $metadata, $forceMetadataRefresh): array {
            $entityTypeId = (int) ($_GET['entityTypeId'] ?? 0);
            return $metadata->remember(
                $memberId,
                "fields:{$entityTypeId}",
                600,
                fn (): array => $app->bitrix($memberId)->fields($entityTypeId),
                $forceMetadataRefresh,
            );
        })(),
        'smart-process-fields.create' => (static function () use ($app, $memberId, $body, $method, $metadata): array {
            if ($method !== 'POST') {
                throw new InvalidArgumentException('Создание поля доступно только методом POST.');
            }
            $entityTypeId = (int) ($body['entityTypeId'] ?? 0);
            $settings = $app->repository->settings($memberId);
            if ($entityTypeId <= 0 || $entityTypeId !== (int) ($settings['entityTypeId'] ?? 0)) {
                throw new InvalidArgumentException('Выберите и сохраните смарт-процесс перед созданием поля.');
            }
            $sourceField = trim((string) ($body['sourceField'] ?? ''));
            $definition = ProviderCatalog::fieldDefinition($sourceField);
            $client = $app->bitrix($memberId);
            $result = $client->ensureField($entityTypeId, $sourceField, $definition);
            $metadata->forget($memberId, "fields:{$entityTypeId}");
            $fields = $client->fields($entityTypeId);
            $app->repository->saveBitrixMetadata($memberId, "fields:{$entityTypeId}", $fields, 600);
            $field = null;
            foreach ($fields as $candidate) {
                if (($candidate['upperName'] ?? null) === ($result['config']['fieldName'] ?? null)) {
                    $field = $candidate;
                    break;
                }
            }
            if ($field === null) {
                foreach ($fields as $candidate) {
                    if (($candidate['title'] ?? null) === ($definition['label'] ?? null)
                        && ($candidate['type'] ?? null) === ($definition['type'] ?? null)
                    ) {
                        $field = $candidate;
                        break;
                    }
                }
            }
            if ($field === null) {
                throw new RuntimeException('Поле создано, но пока не появилось в списке полей Bitrix24. Обновите шаг через несколько секунд.');
            }

            return [
                'created' => (bool) ($result['created'] ?? false),
                'field' => $field,
                'fields' => $fields,
            ];
        })(),
        'expense-fields.create' => (static function () use ($app, $memberId, $body, $method, $metadata): array {
            if ($method !== 'POST') {
                throw new InvalidArgumentException('Создание поля доступно только методом POST.');
            }
            $entityTypeId = (int) ($body['entityTypeId'] ?? 0);
            if ($entityTypeId <= 0) {
                throw new InvalidArgumentException('Выберите смарт-процесс затрат перед созданием поля.');
            }
            $sourceField = trim((string) ($body['sourceField'] ?? ''));
            $definition = ExpenseCatalog::fieldDefinition($sourceField);
            $client = $app->bitrix($memberId);
            $result = $client->ensureField($entityTypeId, $sourceField, $definition);
            $metadata->forget($memberId, "fields:{$entityTypeId}");
            $fields = $client->fields($entityTypeId);
            $app->repository->saveBitrixMetadata($memberId, "fields:{$entityTypeId}", $fields, 600);
            $field = null;
            foreach ($fields as $candidate) {
                if (($candidate['upperName'] ?? null) === ($result['config']['fieldName'] ?? null)) {
                    $field = $candidate;
                    break;
                }
            }
            if ($field === null) {
                foreach ($fields as $candidate) {
                    if (($candidate['title'] ?? null) === ($definition['label'] ?? null)
                        && ($candidate['type'] ?? null) === ($definition['type'] ?? null)
                    ) {
                        $field = $candidate;
                        break;
                    }
                }
            }
            if ($field === null) {
                throw new RuntimeException('Поле затраты создано, но пока не появилось в Bitrix24. Обновите шаг через несколько секунд.');
            }
            return [
                'created' => (bool) ($result['created'] ?? false),
                'field' => $field,
                'fields' => $fields,
            ];
        })(),
        'smart-process-categories' => (static function () use ($app, $memberId, $metadata, $forceMetadataRefresh): array {
            $entityTypeId = (int) ($_GET['entityTypeId'] ?? 0);
            return $metadata->remember(
                $memberId,
                "categories:{$entityTypeId}",
                900,
                fn (): array => $app->bitrix($memberId)->categories($entityTypeId),
                $forceMetadataRefresh,
            );
        })(),
        'smart-process-stages' => (static function () use ($app, $memberId, $metadata, $forceMetadataRefresh): array {
            $entityTypeId = (int) ($_GET['entityTypeId'] ?? 0);
            $categoryId = (int) ($_GET['categoryId'] ?? -1);
            return $metadata->remember(
                $memberId,
                "stages:{$entityTypeId}:{$categoryId}",
                900,
                fn (): array => $app->bitrix($memberId)->stages($entityTypeId, $categoryId),
                $forceMetadataRefresh,
            );
        })(),
        'bitrix-users' => $metadata->remember(
            $memberId,
            'users',
            3600,
            fn (): array => $app->bitrix($memberId)->users(),
            $forceMetadataRefresh,
        ),
        'mappings.get' => $app->repository->mappings($memberId, (string) ($_GET['provider'] ?? '')),
        'mappings.save' => (static function () use ($app, $memberId, $body): array {
            $provider = (string) ($body['provider'] ?? '');
            ProviderCatalog::get($provider);
            $mappings = is_array($body['mappings'] ?? null) ? $body['mappings'] : [];
            $fileTargets = [];
            foreach ($mappings as &$mapping) {
                if (!is_array($mapping)) {
                    continue;
                }
                if (($mapping['sourceField'] ?? '') === 'additional_service_types') {
                    $mapping['transform'] = 'string_multiple';
                }
                if (in_array($mapping['sourceField'] ?? '', ['ticket_file', 'additional_service_files'], true)
                    && !empty($mapping['targetField'])
                ) {
                    if (in_array($mapping['targetField'], $fileTargets, true)) {
                        throw new InvalidArgumentException('Для билета и документов допуслуг выберите разные файловые поля.');
                    }
                    $fileTargets[] = $mapping['targetField'];
                    if ($mapping['sourceField'] === 'additional_service_files') {
                        $mapping['transform'] = 'file_multiple';
                    }
                }
            }
            unset($mapping);
            $app->repository->saveMappings(
                $memberId,
                $provider,
                $mappings,
            );
            return ['saved' => true];
        })(),
        'expense-mappings.get' => $app->repository->mappings($memberId, ExpenseCatalog::MAPPING_SCOPE),
        'expense-mappings.save' => (static function () use ($app, $memberId, $body): array {
            $allowedFields = ExpenseCatalog::fields();
            $mappings = array_values(array_filter(
                is_array($body['mappings'] ?? null) ? $body['mappings'] : [],
                static fn (mixed $mapping): bool => is_array($mapping)
                    && in_array((string) ($mapping['sourceField'] ?? ''), $allowedFields, true),
            ));
            $app->repository->saveMappings($memberId, ExpenseCatalog::MAPPING_SCOPE, $mappings);
            return ['saved' => true];
        })(),
        'settings.get' => $app->repository->settings($memberId),
        'settings.save' => (static function () use ($app, $memberId, $body): array {
            $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
            $settings['entityTypeId'] = max(0, (int) ($settings['entityTypeId'] ?? 0));
            $settings['intervalMinutes'] = in_array((int) ($settings['intervalMinutes'] ?? 15), [5, 10, 15, 30, 60, 180], true)
                ? (int) $settings['intervalMinutes']
                : 15;
            $settings['initialLookbackDays'] = in_array((int) ($settings['initialLookbackDays'] ?? 7), [1, 3, 7, 30], true)
                ? (int) $settings['initialLookbackDays']
                : 7;
            $titleTemplate = trim((string) ($settings['titleTemplate'] ?? ''));
            $settings['titleTemplate'] = function_exists('mb_substr')
                ? mb_substr($titleTemplate, 0, 255)
                : substr($titleTemplate, 0, 255);
            $accommodationTitleTemplate = trim((string) ($settings['accommodationTitleTemplate'] ?? ''));
            $settings['accommodationTitleTemplate'] = function_exists('mb_substr')
                ? mb_substr($accommodationTitleTemplate, 0, 255)
                : substr($accommodationTitleTemplate, 0, 255);
            foreach (['createItems', 'updateItems', 'preserveTitleOnUpdate', 'syncReturns', 'attachDocuments', 'enabled', 'notificationEnabled', 'notifyOnEmpty'] as $flag) {
                $settings[$flag] = !empty($settings[$flag]);
            }
            $settings['notificationUserIds'] = array_values(array_slice(array_unique(array_filter(
                array_map('intval', is_array($settings['notificationUserIds'] ?? null) ? $settings['notificationUserIds'] : []),
                static fn (int $userId): bool => $userId > 0,
            )), 0, 100));
            $assignedById = (int) ($settings['assignedById'] ?? 0);
            $settings['assignedById'] = $assignedById > 0 ? $assignedById : null;
            $expense = is_array($settings['expenseSync'] ?? null) ? $settings['expenseSync'] : [];
            $expenseTitleTemplate = trim((string) ($expense['titleTemplate'] ?? ''));
            $expenseAccommodationTitleTemplate = trim((string) ($expense['accommodationTitleTemplate'] ?? ''));
            $expense = [
                'enabled' => !empty($expense['enabled']),
                'entityTypeId' => max(0, (int) ($expense['entityTypeId'] ?? 0)),
                'categoryId' => isset($expense['categoryId']) && (int) $expense['categoryId'] >= 0
                    ? (int) $expense['categoryId']
                    : null,
                'stageId' => substr(trim((string) ($expense['stageId'] ?? '')), 0, 100),
                'assignedById' => (int) ($expense['assignedById'] ?? 0) > 0
                    ? (int) $expense['assignedById']
                    : null,
                'titleTemplate' => function_exists('mb_substr')
                    ? mb_substr($expenseTitleTemplate, 0, 255)
                    : substr($expenseTitleTemplate, 0, 255),
                'accommodationTitleTemplate' => function_exists('mb_substr')
                    ? mb_substr($expenseAccommodationTitleTemplate, 0, 255)
                    : substr($expenseAccommodationTitleTemplate, 0, 255),
                'createItems' => !array_key_exists('createItems', $expense) || !empty($expense['createItems']),
                'updateItems' => !array_key_exists('updateItems', $expense) || !empty($expense['updateItems']),
                'preserveTitleOnUpdate' => !empty($expense['preserveTitleOnUpdate']),
            ];
            $settings['expenseSync'] = $expense;
            if ($expense['enabled']) {
                if ($expense['entityTypeId'] <= 0 || $expense['categoryId'] === null) {
                    throw new InvalidArgumentException('Для затрат выберите смарт-процесс и воронку.');
                }
                $expenseMappedSources = array_column(
                    $app->repository->mappings($memberId, ExpenseCatalog::MAPPING_SCOPE),
                    'sourceField',
                );
                if (!in_array(ExpenseCatalog::IDENTITY_FIELD, $expenseMappedSources, true)) {
                    throw new InvalidArgumentException('Для затрат сопоставьте обязательное поле «Уникальный ID затраты».');
                }
            }
            if (!empty($settings['notificationEnabled']) && $settings['notificationUserIds'] === []) {
                throw new InvalidArgumentException('Выберите хотя бы одного сотрудника для уведомлений Bitrix24.');
            }
            $cleanRoutes = [];
            $knownTypes = array_keys(ServiceType::all());
            foreach (is_array($settings['serviceRoutes'] ?? null) ? $settings['serviceRoutes'] : [] as $type => $route) {
                if (!in_array($type, $knownTypes, true) || !is_array($route)) {
                    continue;
                }
                $categoryId = $route['categoryId'] ?? null;
                $cleanRoutes[$type] = [
                    'enabled' => !empty($route['enabled']) && $categoryId !== null && (int) $categoryId >= 0,
                    'categoryId' => $categoryId !== null ? max(0, (int) $categoryId) : null,
                    'stageId' => substr(trim((string) ($route['stageId'] ?? '')), 0, 100),
                ];
            }
            $settings['serviceRoutes'] = $cleanRoutes;
            if (!empty($settings['enabled'])) {
                if ((int) ($settings['entityTypeId'] ?? 0) <= 0) {
                    throw new InvalidArgumentException('Выберите смарт-процесс перед запуском синхронизации.');
                }
                $enabledProviders = array_values(array_filter(
                    $app->repository->providers($memberId),
                    static fn (array $connection): bool => (bool) $connection['enabled'],
                ));
                if ($enabledProviders === []) {
                    throw new InvalidArgumentException('Подключите хотя бы одного провайдера.');
                }
                foreach ($enabledProviders as $connection) {
                    $provider = (string) $connection['provider'];
                    $identityField = ProviderCatalog::get($provider)['identityField'];
                    $mappedSources = array_column($app->repository->mappings($memberId, $provider), 'sourceField');
                    if (!in_array($identityField, $mappedSources, true)) {
                        throw new InvalidArgumentException(
                            "Для {$provider} сопоставьте обязательное поле внешнего ID: {$identityField}."
                        );
                    }
                }
                $routes = is_array($settings['serviceRoutes'] ?? null) ? $settings['serviceRoutes'] : [];
                $enabledRoutes = array_filter(
                    $routes,
                    static fn (mixed $route): bool => is_array($route)
                        && !empty($route['enabled'])
                        && isset($route['categoryId']),
                );
                if ($enabledRoutes === []) {
                    throw new InvalidArgumentException('Включите хотя бы один маршрут услуги и выберите для него воронку.');
                }
            }
            $app->repository->saveSettings($memberId, $settings);
            return ['saved' => true];
        })(),
        'sync.run' => (static function () use ($app, $memberId, $body): array {
            $provider = (string) ($body['provider'] ?? '');
            ProviderCatalog::get($provider);
            if (!in_array($provider, array_column($app->repository->enabledProviders($memberId), 'provider'), true)) {
                throw new InvalidArgumentException('Провайдер не подключён или отключён.');
            }
            $trigger = ($body['mode'] ?? 'manual') === 'rescan' ? 'rescan' : 'refresh';
            $runId = $app->repository->enqueueSyncRun($memberId, $provider, $trigger);
            $run = $app->repository->syncRun($runId);
            http_response_code(202);
            return [
                'runId' => $runId,
                'state' => (string) ($run['state'] ?? 'queued'),
                'stats' => is_array($run['stats'] ?? null) ? $run['stats'] : [],
            ];
        })(),
        'sync.runs' => $app->repository->syncRuns($memberId),
        default => throw new InvalidArgumentException('Неизвестное действие API.'),
    };

    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $app->repository->audit($memberId, (int) $session['uid'], 'api.' . $action, array_filter([
            'provider' => isset($body['provider']) ? (string) $body['provider'] : null,
            'entityTypeId' => isset($body['entityTypeId']) ? (int) $body['entityTypeId'] : null,
            'mode' => isset($body['mode']) ? (string) $body['mode'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $requestCompleted = true;
    if ($action !== 'sync.runs') {
        $logger->write('api.completed', [
            'requestId' => $requestId,
            'action' => $action,
            'status' => http_response_code(),
            'durationMs' => (int) round((microtime(true) - $requestStarted) * 1000),
            'memoryPeakBytes' => memory_get_peak_usage(true),
        ]);
    }
} catch (Throwable $exception) {
    $status = in_array((int) $exception->getCode(), [401, 403], true)
        ? (int) $exception->getCode()
        : ($exception instanceof InvalidArgumentException ? 422 : 500);
    if (str_contains($exception->getMessage(), 'сесс')) {
        $status = 401;
    }
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error' => ['message' => $exception->getMessage(), 'requestId' => $requestId],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $requestCompleted = true;
    $logger->write('api.failed', [
        'requestId' => $requestId,
        'action' => (string) ($_GET['action'] ?? 'bootstrap'),
        'status' => $status,
        'durationMs' => (int) round((microtime(true) - $requestStarted) * 1000),
        'memoryPeakBytes' => memory_get_peak_usage(true),
        'errorClass' => $exception::class,
        'error' => $exception->getMessage(),
    ]);
}
