<?php

declare(strict_types=1);

namespace App\Sync;

use App\Application;
use App\Bitrix24\Bitrix24Client;
use Throwable;

final readonly class SyncWorker
{
    public function __construct(private Application $app)
    {
    }

    public function runNext(?string $memberId = null, ?string $provider = null): ?array
    {
        $job = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->app->repository->nextQueuedSyncRun($memberId, $provider);
            if ($candidate === null) {
                break;
            }
            if ($this->app->repository->claimSyncRun((string) $candidate['id'])) {
                $job = $candidate;
                break;
            }
            usleep(20_000);
        }
        if ($job === null) {
            return null;
        }

        $runId = (string) $job['id'];
        $jobMemberId = (string) $job['member_id'];
        $jobProvider = (string) $job['provider'];
        try {
            $bitrix = $this->app->bitrix($jobMemberId);
            $this->assertCrmCapabilities($jobMemberId, $bitrix);
            $result = $this->app->sync()->runClaimed(
                $runId,
                $jobMemberId,
                $jobProvider,
                $bitrix,
            );
            return [
                'runId' => $runId,
                'memberId' => $jobMemberId,
                'provider' => $jobProvider,
            ] + $result;
        } catch (Throwable $exception) {
            $run = $this->app->repository->syncRun($runId);
            $stats = is_array($run['stats'] ?? null) ? $run['stats'] : [];
            if (($run['state'] ?? null) === 'running') {
                $stats['errors'] = max(1, (int) ($stats['errors'] ?? 0));
                $this->app->repository->finishSyncRun($runId, 'failed', $stats, $exception->getMessage());
            }
            return [
                'runId' => $runId,
                'memberId' => $jobMemberId,
                'provider' => $jobProvider,
                'state' => 'failed',
                'stats' => $stats,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function drain(int $maxJobs = 20, ?string $memberId = null, ?string $provider = null): array
    {
        $results = [];
        for ($processed = 0; $processed < max(1, $maxJobs); $processed++) {
            $result = $this->runNext($memberId, $provider);
            if ($result === null) {
                break;
            }
            $results[] = $result;
        }
        return $results;
    }

    private function assertCrmCapabilities(string $memberId, Bitrix24Client $bitrix): void
    {
        $record = $this->app->repository->token($memberId);
        $fingerprint = (string) ($record['token_fingerprint'] ?? '');
        if ($fingerprint === '') {
            $fingerprint = substr(hash('sha256', (string) ($record['access_token'] ?? '')), 0, 12);
        }
        $capabilities = is_array($record['capabilities'] ?? null) ? $record['capabilities'] : [];
        $capabilitiesAreCurrent = $fingerprint !== ''
            && hash_equals($fingerprint, (string) ($record['capabilities_token_fingerprint'] ?? ''))
            && isset(
                $capabilities['crm.item.add'],
                $capabilities['crm.item.update'],
                $capabilities['im.notify.system.add'],
            );

        if (!$capabilitiesAreCurrent) {
            $checked = $bitrix->capabilities([
                'crm.item.add',
                'crm.item.update',
                'im.notify.system.add',
            ]);
            $capabilities = array_replace($capabilities, $checked);
            $latestRecord = $this->app->repository->token($memberId);
            $latestFingerprint = (string) ($latestRecord['token_fingerprint'] ?? '');
            if ($latestFingerprint === '') {
                $latestFingerprint = substr(hash('sha256', (string) ($latestRecord['access_token'] ?? '')), 0, 12);
            }
            // Do not overwrite a token rotated by another process while method.get was running.
            if (hash_equals($fingerprint, $latestFingerprint)) {
                $latestRecord['token_fingerprint'] = $latestFingerprint;
                $latestRecord['capabilities'] = array_replace(
                    is_array($latestRecord['capabilities'] ?? null) ? $latestRecord['capabilities'] : [],
                    $checked,
                );
                $latestRecord['capabilities_checked_at'] = gmdate(DATE_ATOM);
                $latestRecord['capabilities_token_fingerprint'] = $latestFingerprint;
                $this->app->repository->saveTenant($memberId, $latestRecord);
                $this->app->repository->audit($memberId, null, 'oauth.capabilities_checked', [
                    'tokenFingerprint' => $latestFingerprint,
                    'capabilities' => $checked,
                ]);
            }
        }

        foreach (['crm.item.add', 'crm.item.update'] as $method) {
            if (empty($capabilities[$method]['isExisting']) || empty($capabilities[$method]['isAvailable'])) {
                throw new \RuntimeException(
                    "Метод {$method} недоступен текущему OAuth-токену. "
                    . 'Проверьте право crm в настройках локального приложения Bitrix24 '
                    . 'и повторно откройте приложение от имени администратора.'
                );
            }
        }
    }
}
