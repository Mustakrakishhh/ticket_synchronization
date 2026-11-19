<?php

declare(strict_types=1);

namespace App\Bitrix24;

use App\AppRepository;
use App\Diagnostics\AppLogger;

final readonly class BitrixMetadataService
{
    public function __construct(
        private AppRepository $repository,
        private AppLogger $logger,
    ) {
    }

    public function remember(
        string $memberId,
        string $cacheKey,
        int $ttlSeconds,
        callable $loader,
        bool $forceRefresh = false,
    ): array {
        $startedAt = microtime(true);
        $cached = $this->repository->bitrixMetadata($memberId, $cacheKey);
        if (!$forceRefresh && $cached !== null && $cached['fresh']) {
            $this->log($memberId, $cacheKey, $startedAt, true, false, $cached['value']);
            return $cached['value'];
        }

        $lock = $this->acquireRefreshLock($memberId, $cacheKey);
        if ($lock === null) {
            // Another PHP worker is already refreshing this exact directory. Do not
            // start a duplicate Bitrix24 call and exhaust the shared hosting pool.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                usleep(100_000);
                $concurrent = $this->repository->bitrixMetadata($memberId, $cacheKey);
                if ($concurrent !== null && $concurrent['fresh']) {
                    $this->log($memberId, $cacheKey, $startedAt, true, false, $concurrent['value']);
                    return $concurrent['value'];
                }
            }
            if ($cached !== null) {
                $this->log($memberId, $cacheKey, $startedAt, true, true, $cached['value']);
                return $cached['value'];
            }
            throw new \RuntimeException(
                'Справочник Bitrix24 уже обновляется другим запросом. Повторите через несколько секунд.'
            );
        }

        try {
            // The cache may have been populated between the first read and acquiring
            // the lock. Recheck it before making an external request.
            $current = $this->repository->bitrixMetadata($memberId, $cacheKey);
            if (!$forceRefresh && $current !== null && $current['fresh']) {
                $this->log($memberId, $cacheKey, $startedAt, true, false, $current['value']);
                return $current['value'];
            }
            $value = $loader();
            if (!is_array($value)) {
                throw new \RuntimeException('Bitrix24 metadata loader returned a non-array response.');
            }
            $this->repository->saveBitrixMetadata($memberId, $cacheKey, $value, $ttlSeconds);
            $this->log($memberId, $cacheKey, $startedAt, false, false, $value);
            return $value;
        } catch (\Throwable $exception) {
            if ($cached !== null) {
                $this->log($memberId, $cacheKey, $startedAt, true, true, $cached['value'], $exception);
                return $cached['value'];
            }
            $this->log($memberId, $cacheKey, $startedAt, false, false, [], $exception);
            throw $exception;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function forget(string $memberId, string $cacheKey): void
    {
        $this->repository->deleteBitrixMetadata($memberId, $cacheKey);
    }

    /** @return resource|false|null false means locking is unavailable, null means the lock is busy */
    private function acquireRefreshLock(string $memberId, string $cacheKey)
    {
        $directory = dirname(__DIR__, 2) . '/var/metadata-locks';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return false;
        }
        $path = $directory . '/' . hash('sha256', $memberId . '|' . $cacheKey) . '.lock';
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private function log(
        string $memberId,
        string $cacheKey,
        float $startedAt,
        bool $cacheHit,
        bool $stale,
        array $value,
        ?\Throwable $exception = null,
    ): void {
        $this->logger->write('ui.metadata.completed', [
            'memberId' => $memberId,
            'cacheKey' => $cacheKey,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'cacheHit' => $cacheHit,
            'stale' => $stale,
            'records' => count($value),
            'error' => $exception?->getMessage(),
        ]);
    }
}
