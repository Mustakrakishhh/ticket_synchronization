<?php

declare(strict_types=1);

use App\Application;
use App\Diagnostics\AppLogger;
use App\Sync\SyncNotificationService;
use App\Sync\SyncWorker;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['member::', 'provider::']);
$onlyMember = trim((string) ($options['member'] ?? ''));
$onlyProvider = trim((string) ($options['provider'] ?? ''));
$members = $onlyMember !== '' ? [$onlyMember] : $app->repository->activeMembers();
$failed = false;
$queued = [];
$force = $onlyMember !== '' || $onlyProvider !== '';
$logger = new AppLogger(dirname(__DIR__) . '/var/app.log');

foreach ($members as $memberId) {
    $connections = array_values(array_filter(
        $app->repository->enabledProviders($memberId),
        static fn (array $connection): bool => $onlyProvider === ''
            || (string) $connection['provider'] === $onlyProvider,
    ));
    $settings = $app->repository->settings($memberId);
    $interval = max(1, (int) ($settings['intervalMinutes'] ?? 15));
    $providers = array_map(static fn (array $connection): string => (string) $connection['provider'], $connections);
    if (!$force && !$app->repository->isSyncCycleDue($memberId, $providers, $interval)) {
        continue;
    }
    foreach ($connections as $connection) {
        $provider = (string) $connection['provider'];
        try {
            $runId = $app->repository->enqueueSyncRun($memberId, $provider, 'schedule');
            $queued[] = [
                'runId' => $runId,
                'memberId' => $memberId,
                'provider' => $provider,
                'state' => (string) ($app->repository->syncRun($runId)['state'] ?? 'queued'),
            ];
        } catch (Throwable $exception) {
            $failed = true;
            fwrite(STDERR, json_encode([
                'memberId' => $memberId,
                'provider' => $provider,
                'state' => 'failed',
                'error' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
    }
}

foreach ($queued as $job) {
    $logger->write('scheduler.queued', $job);
    fwrite(STDOUT, json_encode(
        $job,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
}

$results = (new SyncWorker($app))->drain(
    20,
    $onlyMember !== '' ? $onlyMember : null,
    $onlyProvider !== '' ? $onlyProvider : null,
);
foreach ($results as $result) {
    $logger->write('worker.finished', $result);
    if (($result['state'] ?? null) === 'failed') {
        $failed = true;
    }
    $stream = ($result['state'] ?? null) === 'failed' ? STDERR : STDOUT;
    fwrite($stream, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
}

$scheduledByMember = [];
foreach ($results as $result) {
    if (($result['stats']['trigger'] ?? null) !== 'schedule') {
        continue;
    }
    $resultMemberId = trim((string) ($result['memberId'] ?? ''));
    if ($resultMemberId !== '') {
        $scheduledByMember[$resultMemberId][] = $result;
    }
}
foreach ($scheduledByMember as $memberId => $memberResults) {
    try {
        $notificationId = $app->repository->enqueueNotification($memberId, $memberResults);
        $logger->write('notification.queued', [
            'memberId' => $memberId,
            'notificationId' => $notificationId,
        ]);
    } catch (Throwable $exception) {
        $failed = true;
        $queueError = [
            'memberId' => $memberId,
            'state' => 'failed',
            'error' => $exception->getMessage(),
        ];
        $logger->write('notification.queue_failed', $queueError);
        fwrite(STDERR, json_encode(
            ['notification' => $queueError],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL);
    }
}

$notificationService = new SyncNotificationService($app->repository);
foreach ($app->repository->pendingNotifications(20) as $pendingNotification) {
    $notificationId = (string) $pendingNotification['id'];
    $memberId = (string) $pendingNotification['member_id'];
    if (!$app->repository->claimNotification($notificationId)) {
        continue;
    }
    try {
        $notification = $notificationService->send(
            $memberId,
            $app->bitrix($memberId),
            is_array($pendingNotification['results'] ?? null) ? $pendingNotification['results'] : [],
        );
        if (in_array($notification['state'] ?? null, ['partial', 'failed'], true)) {
            $error = implode('; ', is_array($notification['errors'] ?? null) ? $notification['errors'] : []);
            $app->repository->retryNotification(
                $notificationId,
                (int) $pendingNotification['attempts'],
                $error !== '' ? $error : 'Bitrix24 не подтвердил доставку уведомления.',
            );
            $failed = true;
        } else {
            $app->repository->finishNotification($notificationId);
        }
        $logger->write('notification.finished', [
            'memberId' => $memberId,
            'notificationId' => $notificationId,
        ] + $notification);
        $stream = ($notification['state'] ?? null) === 'failed' ? STDERR : STDOUT;
        fwrite($stream, json_encode(
            ['memberId' => $memberId, 'notificationId' => $notificationId, 'notification' => $notification],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL);
    } catch (Throwable $exception) {
        $failed = true;
        $app->repository->retryNotification(
            $notificationId,
            (int) $pendingNotification['attempts'],
            $exception->getMessage(),
        );
        $notification = [
            'memberId' => $memberId,
            'notificationId' => $notificationId,
            'state' => 'retry_scheduled',
            'error' => $exception->getMessage(),
        ];
        $logger->write('notification.failed', $notification);
        fwrite(STDERR, json_encode(
            ['notification' => $notification],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL);
    }
}

if ($members === [] && $results === []) {
    fwrite(STDOUT, json_encode([
        'state' => 'idle',
        'message' => 'Нет компаний с включённой автоматической синхронизацией.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}

exit($failed ? 1 : 0);
