<?php

declare(strict_types=1);

use App\Application;
use App\Bitrix24\NotificationClientInterface;
use App\Sync\SyncNotificationService;

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
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$memberId = 'notification-test-member';
$app->repository->saveSettings($memberId, [
    'initialLookbackDays' => 3,
    'notificationEnabled' => true,
    'notificationUserIds' => [5, 7],
    'notifyOnEmpty' => true,
]);

$bitrix = new class implements NotificationClientInterface {
    /** @var list<array{userId: int, message: string, tag: string}> */
    public array $sent = [];

    public function users(): array
    {
        return [
            ['id' => 5, 'name' => 'Иван Иванов', 'position' => 'Бухгалтер'],
            ['id' => 7, 'name' => 'Анна Петрова', 'position' => 'Руководитель'],
        ];
    }

    public function sendSystemNotification(int $userId, string $message, string $tag): void
    {
        $this->sent[] = compact('userId', 'message', 'tag');
    }
};

$service = new SyncNotificationService($app->repository);
$result = $service->send($memberId, $bitrix, [
    [
        'runId' => 'run-a',
        'memberId' => $memberId,
        'provider' => 'aviasales',
        'state' => 'success',
        'stats' => [
            'trigger' => 'schedule',
            'received' => 10,
            'created' => 3,
            'updated' => 2,
            'unchanged' => 4,
            'skipped' => 1,
            'errors' => 0,
        ],
    ],
    [
        'runId' => 'run-b',
        'memberId' => $memberId,
        'provider' => 'trivio',
        'state' => 'partial',
        'stats' => [
            'trigger' => 'schedule',
            'received' => 5,
            'created' => 1,
            'updated' => 0,
            'unchanged' => 3,
            'skipped' => 0,
            'errors' => 1,
        ],
    ],
    [
        'runId' => 'manual-run',
        'memberId' => $memberId,
        'provider' => 'smartway',
        'state' => 'success',
        'stats' => ['trigger' => 'manual', 'received' => 99, 'created' => 99],
    ],
]);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert($result['state'] === 'sent', 'Notification state must be sent.');
$assert($result['sent'] === 2, 'Notification must be sent to both selected users.');
$assert(count($bitrix->sent) === 2, 'Fake Bitrix client must record two notifications.');
$assert(str_contains($bitrix->sent[0]['message'], 'Период полной сверки: последние 3 дн.'), 'Lookback period label is incorrect.');
$assert(str_contains($bitrix->sent[0]['message'], 'Получено от API: 15'), 'Received total is incorrect.');
$assert(str_contains($bitrix->sent[0]['message'], 'Создано карточек: 4'), 'Created total is incorrect.');
$assert(str_contains($bitrix->sent[0]['message'], 'Обновлено карточек: 2'), 'Updated total is incorrect.');
$assert(str_contains($bitrix->sent[0]['message'], 'Ошибок: 1'), 'Error total is incorrect.');
$assert(!str_contains($bitrix->sent[0]['message'], '99'), 'Manual run must not be included.');

$app->repository->saveSettings($memberId, [
    'initialLookbackDays' => 1,
    'notificationEnabled' => true,
    'notificationUserIds' => [5],
    'notifyOnEmpty' => false,
]);
$empty = $service->send($memberId, $bitrix, [[
    'runId' => 'run-empty',
    'memberId' => $memberId,
    'provider' => 'aviasales',
    'state' => 'success',
    'stats' => ['trigger' => 'schedule', 'received' => 4, 'created' => 0, 'updated' => 0, 'errors' => 0],
]]);
$assert($empty['state'] === 'empty', 'Empty cycle must be skipped when notifyOnEmpty is disabled.');
$assert(count($bitrix->sent) === 2, 'Empty cycle must not send another notification.');

$oldOutboxId = $app->repository->enqueueNotification($memberId, [[
    'runId' => 'outbox-run',
    'memberId' => $memberId,
    'provider' => 'trivio',
    'state' => 'success',
    'stats' => ['trigger' => 'schedule', 'received' => 2, 'created' => 0, 'updated' => 2, 'errors' => 0],
]]);
$outboxId = $app->repository->enqueueNotification($memberId, [[
    'runId' => 'outbox-run-newer',
    'memberId' => $memberId,
    'provider' => 'trivio',
    'state' => 'success',
    'stats' => ['trigger' => 'schedule', 'received' => 3, 'created' => 1, 'updated' => 0, 'errors' => 0],
]]);
$pending = $app->repository->pendingNotifications();
$assert(count($pending) === 1 && ($pending[0]['id'] ?? null) === $outboxId, 'Notification outbox did not retain only the newest cycle.');
$assert(!$app->repository->claimNotification($oldOutboxId), 'Superseded notification must not be claimable.');
$assert($app->repository->claimNotification($outboxId), 'Notification outbox item could not be claimed.');
$assert(!$app->repository->claimNotification($outboxId), 'Notification outbox item was claimed twice.');
$app->repository->finishNotification($outboxId);
$assert($app->repository->pendingNotifications() === [], 'Delivered notification remained pending.');

echo "Notification checks passed.\n";
