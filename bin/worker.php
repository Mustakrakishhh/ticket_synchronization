<?php

declare(strict_types=1);

use App\Application;
use App\Diagnostics\AppLogger;
use App\Sync\SyncNotificationService;
use App\Sync\SyncWorker;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['member::', 'provider::', 'max-jobs::']);
$memberId = trim((string) ($options['member'] ?? ''));
$provider = trim((string) ($options['provider'] ?? ''));
$maxJobs = max(1, min(100, (int) ($options['max-jobs'] ?? 20)));
$results = (new SyncWorker($app))->drain(
    $maxJobs,
    $memberId !== '' ? $memberId : null,
    $provider !== '' ? $provider : null,
);
$logger = new AppLogger(dirname(__DIR__) . '/var/app.log');

foreach ($results as $result) {
    $logger->write('worker.finished', $result);
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
foreach ($scheduledByMember as $resultMemberId => $memberResults) {
    try {
        $notificationId = $app->repository->enqueueNotification($resultMemberId, $memberResults);
        $logger->write('notification.queued', [
            'memberId' => $resultMemberId,
            'notificationId' => $notificationId,
            'source' => 'worker',
        ]);
    } catch (Throwable $exception) {
        $logger->write('notification.queue_failed', [
            'memberId' => $resultMemberId,
            'error' => $exception->getMessage(),
            'source' => 'worker',
        ]);
    }
}

$notificationService = new SyncNotificationService($app->repository);
foreach ($app->repository->pendingNotifications(20) as $pendingNotification) {
    $notificationId = (string) $pendingNotification['id'];
    $resultMemberId = (string) $pendingNotification['member_id'];
    if (!$app->repository->claimNotification($notificationId)) {
        continue;
    }
    try {
        $notification = $notificationService->send(
            $resultMemberId,
            $app->bitrix($resultMemberId),
            is_array($pendingNotification['results'] ?? null) ? $pendingNotification['results'] : [],
        );
        if (in_array($notification['state'] ?? null, ['partial', 'failed'], true)) {
            $error = implode('; ', is_array($notification['errors'] ?? null) ? $notification['errors'] : []);
            $app->repository->retryNotification(
                $notificationId,
                (int) $pendingNotification['attempts'],
                $error !== '' ? $error : 'Bitrix24 не подтвердил доставку уведомления.',
            );
        } else {
            $app->repository->finishNotification($notificationId);
        }
        $logger->write('notification.finished', [
            'memberId' => $resultMemberId,
            'notificationId' => $notificationId,
            'source' => 'worker',
        ] + $notification);
    } catch (Throwable $exception) {
        $app->repository->retryNotification(
            $notificationId,
            (int) $pendingNotification['attempts'],
            $exception->getMessage(),
        );
        $logger->write('notification.failed', [
            'memberId' => $resultMemberId,
            'notificationId' => $notificationId,
            'state' => 'retry_scheduled',
            'error' => $exception->getMessage(),
            'source' => 'worker',
        ]);
    }
}

if ($results === []) {
    fwrite(STDOUT, json_encode([
        'state' => 'idle',
        'message' => 'Задач синхронизации в очереди нет.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
}

$failed = array_filter($results, static fn (array $result): bool => ($result['state'] ?? null) === 'failed');
exit($failed !== [] ? 1 : 0);
