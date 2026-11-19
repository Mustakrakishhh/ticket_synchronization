<?php

declare(strict_types=1);

namespace App\Sync;

use App\AppRepository;
use App\Bitrix24\NotificationClientInterface;

final readonly class SyncNotificationService
{
    public function __construct(private AppRepository $repository)
    {
    }

    /** @param list<array<string, mixed>> $results */
    public function send(
        string $memberId,
        NotificationClientInterface $bitrix,
        array $results,
    ): array {
        $settings = $this->repository->settings($memberId);
        $userIds = array_values(array_filter(
            array_map('intval', is_array($settings['notificationUserIds'] ?? null) ? $settings['notificationUserIds'] : []),
            static fn (int $userId): bool => $userId > 0,
        ));
        if (empty($settings['notificationEnabled']) || $userIds === []) {
            return ['state' => 'disabled', 'sent' => 0, 'errors' => []];
        }

        $scheduled = array_values(array_filter(
            $results,
            static fn (array $result): bool => ($result['stats']['trigger'] ?? null) === 'schedule',
        ));
        if ($scheduled === []) {
            return ['state' => 'skipped', 'sent' => 0, 'errors' => []];
        }

        $totals = [
            'received' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'errors' => 0,
            'expenseReceived' => 0,
            'expenseCreated' => 0,
            'expenseUpdated' => 0,
            'expenseUnchanged' => 0,
            'expenseSkipped' => 0,
            'expenseErrors' => 0,
        ];
        $providerLines = [];
        $runIds = [];
        foreach ($scheduled as $result) {
            $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
            foreach (array_keys($totals) as $field) {
                $totals[$field] += (int) ($stats[$field] ?? 0);
            }
            $provider = (string) ($result['provider'] ?? 'provider');
            $providerLines[] = sprintf(
                '%s: получено %d, создано %d, обновлено %d, ошибок %d',
                ucfirst($provider),
                (int) ($stats['received'] ?? 0),
                (int) ($stats['created'] ?? 0),
                (int) ($stats['updated'] ?? 0),
                (int) ($stats['errors'] ?? 0),
            );
            if ((int) ($stats['expenseReceived'] ?? 0) > 0
                || (int) ($stats['expenseErrors'] ?? 0) > 0
            ) {
                $providerLines[] = sprintf(
                    '  Затраты: подготовлено %d, создано %d, обновлено %d, ошибок %d',
                    (int) ($stats['expenseReceived'] ?? 0),
                    (int) ($stats['expenseCreated'] ?? 0),
                    (int) ($stats['expenseUpdated'] ?? 0),
                    (int) ($stats['expenseErrors'] ?? 0),
                );
            }
            $runIds[] = (string) ($result['runId'] ?? '');
        }

        if (empty($settings['notifyOnEmpty'])
            && $totals['created'] === 0
            && $totals['updated'] === 0
            && $totals['expenseCreated'] === 0
            && $totals['expenseUpdated'] === 0
            && $totals['errors'] === 0
        ) {
            return ['state' => 'empty', 'sent' => 0, 'errors' => [], 'totals' => $totals];
        }

        $days = max(1, min(30, (int) ($settings['initialLookbackDays'] ?? 7)));
        $message = implode("\n", [
            '[B]Travel Sync: автоматическая синхронизация завершена[/B]',
            "Период полной сверки: последние {$days} дн.",
            ...$providerLines,
            '',
            "Получено от API: {$totals['received']}",
            "Создано карточек: {$totals['created']}",
            "Обновлено карточек: {$totals['updated']}",
            "Без изменений: {$totals['unchanged']}",
            "Пропущено: {$totals['skipped']}",
            "Ошибок: {$totals['errors']}",
            '',
            "Карточек затрат подготовлено: {$totals['expenseReceived']}",
            "Карточек затрат создано: {$totals['expenseCreated']}",
            "Карточек затрат обновлено: {$totals['expenseUpdated']}",
            "Затрат без изменений: {$totals['expenseUnchanged']}",
            "Допуслуг без найденного билета: {$totals['expenseSkipped']}",
        ]);
        $tag = 'travel_sync_' . substr(hash('sha256', $memberId . implode('|', $runIds)), 0, 32);
        $sent = 0;
        $errors = [];
        foreach (array_unique($userIds) as $userId) {
            try {
                $bitrix->sendSystemNotification($userId, $message, $tag);
                $sent++;
            } catch (\Throwable $exception) {
                $errors[] = "Пользователь {$userId}: " . $exception->getMessage();
            }
        }

        return [
            'state' => $errors === [] ? 'sent' : ($sent > 0 ? 'partial' : 'failed'),
            'sent' => $sent,
            'errors' => $errors,
            'totals' => $totals,
        ];
    }
}
