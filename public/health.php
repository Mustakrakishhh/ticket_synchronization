<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$started = microtime(true);
try {
    $app = require dirname(__DIR__) . '/bootstrap.php';
    if ($app->config->healthCheckToken !== '') {
        $provided = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $expected = 'Bearer ' . $app->config->healthCheckToken;
        if (!hash_equals($expected, $provided)) {
            http_response_code(401);
            echo json_encode(['status' => 'unauthorized'], JSON_THROW_ON_ERROR);
            exit;
        }
    }
    $counts = [];
    foreach (['queued', 'running'] as $state) {
        $statement = $app->database->pdo->prepare('SELECT COUNT(*) FROM sync_runs WHERE state = :state');
        $statement->execute(['state' => $state]);
        $counts[$state] = (int) $statement->fetchColumn();
    }
    $notificationStatement = $app->database->pdo->query(
        "SELECT COUNT(*) FROM notification_outbox WHERE state IN ('pending', 'sending')"
    );
    $configuredScopes = array_values(array_filter(array_map(
        static fn (string $scope): string => strtolower(trim($scope)),
        explode(',', $app->config->scope),
    )));
    $requiredScopes = ['crm', 'im', 'user_basic', 'userfieldconfig'];
    $oauthCapabilities = [
        'checked' => 0,
        'unknown' => 0,
        'missingCrmItemAdd' => 0,
        'missingNotifications' => 0,
    ];
    foreach ($app->repository->activeMembers() as $memberId) {
        $token = $app->repository->token($memberId);
        $capabilities = is_array($token['capabilities'] ?? null) ? $token['capabilities'] : [];
        if ($capabilities === []) {
            $oauthCapabilities['unknown']++;
            continue;
        }
        $oauthCapabilities['checked']++;
        if (empty($capabilities['crm.item.add']['isAvailable'])) {
            $oauthCapabilities['missingCrmItemAdd']++;
        }
        if (empty($capabilities['im.notify.system.add']['isAvailable'])) {
            $oauthCapabilities['missingNotifications']++;
        }
    }
    echo json_encode([
        'status' => 'ok',
        'version' => '0.8.0',
        'time' => gmdate(DATE_ATOM),
        'database' => [
            'driver' => (string) $app->database->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        ],
        'queue' => $counts,
        'notifications' => [
            'pending' => (int) $notificationStatement->fetchColumn(),
        ],
        'configuration' => [
            'missingScopes' => array_values(array_diff($requiredScopes, $configuredScopes)),
            'oauthCapabilities' => $oauthCapabilities,
        ],
        'durationMs' => (int) round((microtime(true) - $started) * 1000),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'time' => gmdate(DATE_ATOM),
        'message' => 'Application bootstrap failed.',
        'durationMs' => (int) round((microtime(true) - $started) * 1000),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
