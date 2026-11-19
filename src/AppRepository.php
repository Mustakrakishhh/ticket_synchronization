<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final readonly class AppRepository
{
    private const STALE_LOCK_SECONDS = 600;

    public function __construct(
        private PDO $pdo,
        private Crypto $crypto,
    ) {
    }

    public function saveTenant(string $memberId, array $token): void
    {
        if ($memberId === '') {
            throw new RuntimeException('Bitrix24 не передал member_id.');
        }
        $tokenMemberId = trim((string) ($token['member_id'] ?? ''));
        if ($tokenMemberId !== '' && !hash_equals($memberId, $tokenMemberId)) {
            throw new RuntimeException('OAuth member_id does not match the target tenant.');
        }
        $existing = $this->tenant($memberId);
        $now = gmdate(DATE_ATOM);
        $values = [
            'member_id' => $memberId,
            'domain' => (string) ($token['domain'] ?? ''),
            'client_endpoint' => (string) ($token['client_endpoint'] ?? ''),
            'oauth_payload' => $this->crypto->encrypt($token),
            'application_token_hash' => !empty($token['application_token'])
                ? password_hash((string) $token['application_token'], PASSWORD_DEFAULT)
                : ($existing['application_token_hash'] ?? null),
            'status' => 'active',
            'installed_at' => $existing['installed_at'] ?? $now,
            'updated_at' => $now,
        ];
        $this->replace('tenants', 'member_id', $values);
    }

    public function tenant(string $memberId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tenants WHERE member_id = :id');
        $statement->execute(['id' => $memberId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function token(string $memberId): array
    {
        $row = $this->tenant($memberId);
        if ($row === null || $row['status'] !== 'active') {
            throw new RuntimeException('Установка Bitrix24 не найдена или отключена.');
        }
        return $this->crypto->decrypt((string) $row['oauth_payload']);
    }

    public function deactivateTenant(string $memberId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tenants SET status = :status, updated_at = :updated WHERE member_id = :id'
        );
        $statement->execute(['status' => 'uninstalled', 'updated' => gmdate(DATE_ATOM), 'id' => $memberId]);
    }

    public function purgeTenant(string $memberId): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ([
                'field_mappings',
                'audit_events',
                'tenant_users',
                'queue_guards',
                'sync_items',
                'sync_runs',
                'sync_cursors',
                'sync_locks',
                'provider_connections',
                'notification_outbox',
                'bitrix_metadata_cache',
                'tenant_settings',
                'tenants',
            ] as $table) {
                $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE member_id = :id");
                $statement->execute(['id' => $memberId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function verifyApplicationToken(string $memberId, string $token): bool
    {
        $hash = (string) ($this->tenant($memberId)['application_token_hash'] ?? '');
        return $hash !== '' && $token !== '' && password_verify($token, $hash);
    }

    public function recordUser(string $memberId, int $userId, string $name, bool $isAdmin): void
    {
        if ($memberId === '' || $userId <= 0) {
            throw new RuntimeException('Cannot record an empty Bitrix24 user identity.');
        }
        $this->replaceComposite('tenant_users', ['member_id', 'user_id'], [
            'member_id' => $memberId,
            'user_id' => $userId,
            'display_name' => substr(trim($name), 0, 255),
            'is_admin' => $isAdmin ? 1 : 0,
            'last_seen_at' => gmdate(DATE_ATOM),
        ]);
    }

    /** @return array{value: array, fresh: bool, updatedAt: string}|null */
    public function bitrixMetadata(string $memberId, string $cacheKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT payload_json, expires_at, updated_at
             FROM bitrix_metadata_cache
             WHERE member_id = :member AND cache_key = :cache_key'
        );
        $statement->execute(['member' => $memberId, 'cache_key' => $cacheKey]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $value = json_decode((string) $row['payload_json'], true);
        if (!is_array($value)) {
            $this->deleteBitrixMetadata($memberId, $cacheKey);
            return null;
        }
        return [
            'value' => $value,
            'fresh' => (string) $row['expires_at'] > gmdate(DATE_ATOM),
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    public function saveBitrixMetadata(string $memberId, string $cacheKey, array $value, int $ttlSeconds): void
    {
        $now = time();
        $this->replaceComposite('bitrix_metadata_cache', ['member_id', 'cache_key'], [
            'member_id' => $memberId,
            'cache_key' => substr($cacheKey, 0, 191),
            'payload_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'expires_at' => gmdate(DATE_ATOM, $now + max(30, $ttlSeconds)),
            'updated_at' => gmdate(DATE_ATOM, $now),
        ]);
    }

    public function deleteBitrixMetadata(string $memberId, string $cacheKey): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM bitrix_metadata_cache WHERE member_id = :member AND cache_key = :cache_key'
        );
        $statement->execute(['member' => $memberId, 'cache_key' => $cacheKey]);
    }

    public function audit(string $memberId, ?int $userId, string $action, array $context = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events (id, member_id, user_id, action, context_json, created_at)
             VALUES (:id, :member, :user, :action, :context, :created)'
        );
        $statement->execute([
            'id' => bin2hex(random_bytes(16)),
            'member' => $memberId,
            'user' => $userId,
            'action' => substr($action, 0, 100),
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created' => gmdate(DATE_ATOM),
        ]);
    }

    public function providers(string $memberId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT provider, enabled, verified_at, last_error, updated_at
             FROM provider_connections WHERE member_id = :id ORDER BY provider'
        );
        $statement->execute(['id' => $memberId]);
        return $statement->fetchAll();
    }

    public function enabledProviders(string $memberId): array
    {
        return array_values(array_filter(
            $this->providers($memberId),
            static fn (array $provider): bool => (bool) $provider['enabled'],
        ));
    }

    public function activeMembers(): array
    {
        $statement = $this->pdo->query(
            'SELECT t.member_id
             FROM tenants t
             INNER JOIN tenant_settings s ON s.member_id = t.member_id
             WHERE t.status = \'active\' AND s.enabled = 1
             ORDER BY t.member_id'
        );
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function saveProvider(string $memberId, string $provider, array $credentials, bool $enabled = true): void
    {
        $existing = $this->providerRow($memberId, $provider);
        $values = [
            'member_id' => $memberId,
            'provider' => $provider,
            'credentials_payload' => $this->crypto->encrypt($credentials),
            'enabled' => $enabled ? 1 : 0,
            'verified_at' => $existing['verified_at'] ?? null,
            'last_error' => null,
            'updated_at' => gmdate(DATE_ATOM),
        ];
        $this->replaceComposite('provider_connections', ['member_id', 'provider'], $values);
    }

    public function providerCredentials(string $memberId, string $provider): array
    {
        $row = $this->providerRow($memberId, $provider);
        if ($row === null) {
            throw new RuntimeException('Подключение провайдера не настроено.');
        }
        return $this->crypto->decrypt((string) $row['credentials_payload']);
    }

    public function markProviderChecked(string $memberId, string $provider, ?string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE provider_connections
             SET verified_at = CASE WHEN :error_check IS NULL THEN :verified ELSE verified_at END,
                 last_error = :error_value,
                 updated_at = :updated
             WHERE member_id = :member AND provider = :provider'
        );
        $statement->execute([
            'verified' => $error === null ? gmdate(DATE_ATOM) : null,
            'error_check' => $error,
            'error_value' => $error,
            'updated' => gmdate(DATE_ATOM),
            'member' => $memberId,
            'provider' => $provider,
        ]);
    }

    public function settings(string $memberId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tenant_settings WHERE member_id = :id');
        $statement->execute(['id' => $memberId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return [
                'entityTypeId' => null,
                'enabled' => false,
                'intervalMinutes' => 15,
                'createItems' => true,
                'updateItems' => true,
                'preserveTitleOnUpdate' => false,
                'syncReturns' => true,
                'attachDocuments' => false,
                'notificationEnabled' => false,
                'notificationUserIds' => [],
                'assignedById' => null,
                'notifyOnEmpty' => true,
                'serviceRoutes' => [],
                'titleTemplate' => '{provider}: билет {ticket_number} — {passenger}',
                'accommodationTitleTemplate' => '{provider}: проживание {hotel_name} — {passenger}',
                'expenseSync' => [
                    'enabled' => false,
                    'entityTypeId' => null,
                    'categoryId' => null,
                    'stageId' => '',
                    'assignedById' => null,
                    'titleTemplate' => 'Затрата: билет {ticket_number} — {passenger}',
                    'accommodationTitleTemplate' => 'Затрата: проживание {hotel_name} — {passenger}',
                    'createItems' => true,
                    'updateItems' => true,
                    'preserveTitleOnUpdate' => false,
                ],
            ];
        }
        $settings = json_decode((string) $row['settings_json'], true, flags: JSON_THROW_ON_ERROR);
        // Settings created before return synchronization was introduced do not
        // contain this flag. Treat them like a fresh installation, where returns
        // are enabled, while preserving an explicitly saved false value.
        $settings += ['syncReturns' => true];
        return array_merge($settings, [
            'entityTypeId' => $row['entity_type_id'] !== null ? (int) $row['entity_type_id'] : null,
            'enabled' => (bool) $row['enabled'],
        ]);
    }

    public function setupComplete(string $memberId): bool
    {
        $settings = $this->settings($memberId);
        if ((int) ($settings['entityTypeId'] ?? 0) <= 0) {
            return false;
        }

        $hasEnabledRoute = false;
        foreach (is_array($settings['serviceRoutes'] ?? null) ? $settings['serviceRoutes'] : [] as $route) {
            if (is_array($route) && !empty($route['enabled']) && isset($route['categoryId'])) {
                $hasEnabledRoute = true;
                break;
            }
        }
        if (!$hasEnabledRoute) {
            return false;
        }

        $providers = $this->enabledProviders($memberId);
        if ($providers === []) {
            return false;
        }
        foreach ($providers as $provider) {
            $hasExternalId = false;
            foreach ($this->mappings($memberId, (string) $provider['provider']) as $mapping) {
                if (($mapping['sourceField'] ?? '') === 'external_id'
                    && trim((string) ($mapping['targetField'] ?? '')) !== ''
                ) {
                    $hasExternalId = true;
                    break;
                }
            }
            if (!$hasExternalId) {
                return false;
            }
        }

        return true;
    }

    public function saveSettings(string $memberId, array $settings): void
    {
        $entityTypeId = isset($settings['entityTypeId']) ? (int) $settings['entityTypeId'] : null;
        $enabled = !empty($settings['enabled']);
        unset($settings['entityTypeId'], $settings['enabled']);
        $this->replace('tenant_settings', 'member_id', [
            'member_id' => $memberId,
            'entity_type_id' => $entityTypeId,
            'settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function mappings(string $memberId, string $provider): array
    {
        $statement = $this->pdo->prepare(
            'SELECT source_field AS sourceField, target_field AS targetField, transform_name AS transform
             FROM field_mappings WHERE member_id = :id AND provider = :provider ORDER BY source_field'
        );
        $statement->execute(['id' => $memberId, 'provider' => $provider]);
        return $statement->fetchAll();
    }

    public function saveMappings(string $memberId, string $provider, array $mappings): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM field_mappings WHERE member_id = :id AND provider = :provider'
            );
            $delete->execute(['id' => $memberId, 'provider' => $provider]);
            $insert = $this->pdo->prepare(
                'INSERT INTO field_mappings
                 (member_id, provider, source_field, target_field, transform_name)
                 VALUES (:member, :provider, :source, :target, :transform)'
            );
            foreach ($mappings as $mapping) {
                if (empty($mapping['sourceField']) || empty($mapping['targetField'])) {
                    continue;
                }
                $insert->execute([
                    'member' => $memberId,
                    'provider' => $provider,
                    'source' => (string) $mapping['sourceField'],
                    'target' => (string) $mapping['targetField'],
                    'transform' => ($mapping['transform'] ?? '') !== '' ? (string) $mapping['transform'] : null,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function syncCursor(string $memberId, string $provider): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT cursor_value FROM sync_cursors WHERE member_id = :member AND provider = :provider'
        );
        $statement->execute(['member' => $memberId, 'provider' => $provider]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null || $value === '' ? null : (string) $value;
    }

    public function saveSyncCursor(string $memberId, string $provider, ?string $cursor): void
    {
        $this->replaceComposite('sync_cursors', ['member_id', 'provider'], [
            'member_id' => $memberId,
            'provider' => $provider,
            'cursor_value' => $cursor,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function syncItem(string $memberId, string $provider, int $entityTypeId, string $externalId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM sync_items
             WHERE member_id = :member AND provider = :provider
               AND entity_type_id = :entity AND external_id = :external'
        );
        $statement->execute([
            'member' => $memberId,
            'provider' => $provider,
            'entity' => $entityTypeId,
            'external' => $externalId,
        ]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function saveSyncItem(
        string $memberId,
        string $provider,
        int $entityTypeId,
        string $externalId,
        ?int $bitrixItemId,
        string $checksum,
        string $state,
        array $payload,
        ?string $error = null,
    ): void {
        $this->replaceComposite('sync_items', ['member_id', 'provider', 'entity_type_id', 'external_id'], [
            'member_id' => $memberId,
            'provider' => $provider,
            'entity_type_id' => $entityTypeId,
            'external_id' => $externalId,
            'bitrix_item_id' => $bitrixItemId,
            'checksum' => $checksum,
            'state' => $state,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'last_error' => $error,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function startSyncRun(string $memberId, string $provider, string $trigger): string
    {
        $id = $this->enqueueSyncRun($memberId, $provider, $trigger);
        if (!$this->claimSyncRun($id)) {
            throw new RuntimeException("Синхронизация {$provider} уже выполняется.");
        }
        return $id;
    }

    public function enqueueSyncRun(string $memberId, string $provider, string $trigger): string
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockQueueKey($memberId, $provider);
            $this->recoverStaleRuns($memberId, $provider);
            $pending = $this->pdo->prepare(
                'SELECT id FROM sync_runs
                 WHERE member_id = :member AND provider = :provider
                   AND state IN (\'queued\', \'running\')
                 ORDER BY started_at ASC
                 LIMIT 1'
            );
            $pending->execute(['member' => $memberId, 'provider' => $provider]);
            $existing = $pending->fetchColumn();
            if (is_string($existing) && $existing !== '') {
                $this->pdo->commit();
                return $existing;
            }

            $id = bin2hex(random_bytes(16));
            $now = gmdate(DATE_ATOM);
            $statement = $this->pdo->prepare(
                'INSERT INTO sync_runs
                 (id, member_id, provider, state, stats_json, error_text, started_at, finished_at)
                 VALUES (:id, :member, :provider, \'queued\', :stats, NULL, :started, NULL)'
            );
            $statement->execute([
                'id' => $id,
                'member' => $memberId,
                'provider' => $provider,
                'stats' => json_encode([
                    'trigger' => $trigger,
                    'queuedAt' => $now,
                    'received' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                    'documents' => 0,
                    'documentErrors' => 0,
                ], JSON_THROW_ON_ERROR),
                'started' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $id;
    }

    private function lockQueueKey(string $memberId, string $provider): void
    {
        $now = gmdate(DATE_ATOM);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insert = $driver === 'mysql'
            ? 'INSERT IGNORE INTO queue_guards (member_id, provider, touched_at) VALUES (:member, :provider, :touched)'
            : 'INSERT OR IGNORE INTO queue_guards (member_id, provider, touched_at) VALUES (:member, :provider, :touched)';
        $this->pdo->prepare($insert)->execute([
            'member' => $memberId,
            'provider' => $provider,
            'touched' => $now,
        ]);
        $sql = 'SELECT member_id FROM queue_guards WHERE member_id = :member AND provider = :provider';
        if ($driver === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['member' => $memberId, 'provider' => $provider]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Could not acquire queue guard.');
        }
        $touch = $this->pdo->prepare(
            'UPDATE queue_guards SET touched_at = :touched WHERE member_id = :member AND provider = :provider'
        );
        $touch->execute(['touched' => $now, 'member' => $memberId, 'provider' => $provider]);
    }

    public function nextQueuedSyncRun(?string $memberId = null, ?string $provider = null): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $this->recoverStaleRuns();
            $sql = 'SELECT id, member_id, provider, stats_json, started_at
                    FROM sync_runs WHERE state = \'queued\'';
            $parameters = [];
            if ($memberId !== null) {
                $sql .= ' AND member_id = :member';
                $parameters['member'] = $memberId;
            }
            if ($provider !== null) {
                $sql .= ' AND provider = :provider';
                $parameters['provider'] = $provider;
            }
            $sql .= ' ORDER BY started_at ASC LIMIT 1';
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            $row = $statement->fetch();
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        if (!is_array($row)) {
            return null;
        }
        $row['stats'] = json_decode((string) $row['stats_json'], true) ?: [];
        unset($row['stats_json']);
        return $row;
    }

    public function claimSyncRun(string $runId): bool
    {
        $memberId = null;
        $provider = null;
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT member_id, provider, state, stats_json FROM sync_runs WHERE id = :id'
            );
            $statement->execute(['id' => $runId]);
            $run = $statement->fetch();
            if (!is_array($run) || $run['state'] !== 'queued') {
                $this->pdo->commit();
                return false;
            }
            $memberId = (string) $run['member_id'];
            $provider = (string) $run['provider'];
            $this->recoverStaleRuns($memberId, $provider);

            $lock = $this->pdo->prepare(
                'INSERT INTO sync_locks (member_id, provider, run_id, locked_at)
                 VALUES (:member, :provider, :run, :locked)'
            );
            $now = gmdate(DATE_ATOM);
            $lock->execute([
                'member' => $memberId,
                'provider' => $provider,
                'run' => $runId,
                'locked' => $now,
            ]);
            $stats = json_decode((string) $run['stats_json'], true) ?: [];
            $stats['startedProcessingAt'] = $now;
            $update = $this->pdo->prepare(
                'UPDATE sync_runs SET state = \'running\', stats_json = :stats WHERE id = :id AND state = \'queued\''
            );
            $update->execute([
                'id' => $runId,
                'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Не удалось захватить задачу синхронизации.');
            }
            $this->pdo->commit();
            return true;
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($memberId !== null && $provider !== null && $this->providerIsLocked($memberId, $provider)) {
                return false;
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function heartbeatSyncRun(string $runId, array $stats): void
    {
        $now = gmdate(DATE_ATOM);
        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare(
                'UPDATE sync_runs SET stats_json = :stats WHERE id = :id AND state = \'running\''
            );
            $run->execute([
                'id' => $runId,
                'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $lock = $this->pdo->prepare('UPDATE sync_locks SET locked_at = :locked WHERE run_id = :id');
            $lock->execute(['locked' => $now, 'id' => $runId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function syncRun(string $runId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, member_id, provider, state, stats_json, error_text, started_at, finished_at
             FROM sync_runs WHERE id = :id'
        );
        $statement->execute(['id' => $runId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $row['stats'] = json_decode((string) $row['stats_json'], true) ?: [];
        unset($row['stats_json']);
        return $row;
    }

    public function finishSyncRun(string $runId, string $state, array $stats, ?string $error = null): void
    {
        $this->pdo->beginTransaction();
        try {
        $statement = $this->pdo->prepare(
            'UPDATE sync_runs
             SET state = :state, stats_json = :stats, error_text = :error, finished_at = :finished
             WHERE id = :id'
        );
        $statement->execute([
            'state' => $state,
            'stats' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'error' => $error,
            'finished' => gmdate(DATE_ATOM),
            'id' => $runId,
        ]);
            $unlock = $this->pdo->prepare('DELETE FROM sync_locks WHERE run_id = :id');
            $unlock->execute(['id' => $runId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function recoverStaleRuns(?string $memberId = null, ?string $provider = null): void
    {
        $sql = 'SELECT run_id FROM sync_locks WHERE locked_at < :stale';
        $parameters = ['stale' => gmdate(DATE_ATOM, time() - self::STALE_LOCK_SECONDS)];
        if ($memberId !== null) {
            $sql .= ' AND member_id = :member';
            $parameters['member'] = $memberId;
        }
        if ($provider !== null) {
            $sql .= ' AND provider = :provider';
            $parameters['provider'] = $provider;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $runIds = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if ($runIds === []) {
            return;
        }
        $fail = $this->pdo->prepare(
            'UPDATE sync_runs
             SET state = \'failed\', error_text = :error, finished_at = :finished
             WHERE id = :id AND state = \'running\''
        );
        $unlock = $this->pdo->prepare('DELETE FROM sync_locks WHERE run_id = :id');
        foreach ($runIds as $runId) {
            $fail->execute([
                'id' => $runId,
                'error' => 'Worker перестал передавать heartbeat более 10 минут. Задача остановлена.',
                'finished' => gmdate(DATE_ATOM),
            ]);
            $unlock->execute(['id' => $runId]);
        }
    }

    private function providerIsLocked(string $memberId, string $provider): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM sync_locks WHERE member_id = :member AND provider = :provider'
        );
        $statement->execute(['member' => $memberId, 'provider' => $provider]);
        return $statement->fetchColumn() !== false;
    }

    public function syncRuns(string $memberId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $statement = $this->pdo->prepare(
            'SELECT id, provider, state, stats_json, error_text, started_at, finished_at
             FROM sync_runs WHERE member_id = :member
             ORDER BY started_at DESC
             LIMIT ' . $limit
        );
        $statement->execute(['member' => $memberId]);
        $rows = $statement->fetchAll();
        return array_map(static function (array $row): array {
            $row['stats'] = json_decode((string) $row['stats_json'], true) ?: [];
            unset($row['stats_json']);
            return $row;
        }, $rows);
    }

    public function isSyncDue(string $memberId, string $provider, int $intervalMinutes): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT state, started_at FROM sync_runs
             WHERE member_id = :member AND provider = :provider
             ORDER BY started_at DESC
             LIMIT 1'
        );
        $statement->execute(['member' => $memberId, 'provider' => $provider]);
        $last = $statement->fetch();
        if (!is_array($last) || !is_string($last['started_at'] ?? null) || $last['started_at'] === '') {
            return true;
        }
        if (in_array($last['state'] ?? null, ['queued', 'running'], true)) {
            return false;
        }
        try {
            if (($last['state'] ?? null) === 'failed') {
                // A permanent authorization/configuration error must not turn an
                // every-minute cron into an endless five-minute request storm.
                $retryMinutes = max(5, $intervalMinutes);
                return (new \DateTimeImmutable($last['started_at']))->modify("+{$retryMinutes} minutes")
                    <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
            return (new \DateTimeImmutable($last['started_at']))->modify('+' . max(1, $intervalMinutes) . ' minutes')
                <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return true;
        }
    }

    /** @param list<string> $providers */
    public function isSyncCycleDue(string $memberId, array $providers, int $intervalMinutes): bool
    {
        $providers = array_values(array_unique(array_filter(
            array_map('strval', $providers),
            static fn (string $provider): bool => $provider !== '',
        )));
        if ($providers === []) {
            return false;
        }
        foreach ($providers as $provider) {
            if (!$this->isSyncDue($memberId, $provider, $intervalMinutes)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string, mixed>> $results */
    public function enqueueNotification(string $memberId, array $results): string
    {
        $runIds = array_values(array_filter(array_map(
            static fn (array $result): string => trim((string) ($result['runId'] ?? '')),
            $results,
        )));
        sort($runIds);
        if ($memberId === '' || $runIds === []) {
            throw new RuntimeException('Нельзя поставить пустое уведомление в очередь.');
        }
        $id = hash('sha256', $memberId . '|' . implode('|', $runIds));
        $now = gmdate(DATE_ATOM);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT IGNORE INTO notification_outbox '
            : 'INSERT OR IGNORE INTO notification_outbox ';
        $sql .= '(id, member_id, payload_json, state, attempts, next_attempt_at, last_error, created_at, sent_at)
                 VALUES (:id, :member, :payload, \'pending\', 0, :next_attempt, NULL, :created, NULL)';
        $this->pdo->beginTransaction();
        try {
            // Only the newest unsent scheduled report is useful. Without coalescing,
            // a temporary loss of the im scope produces a burst of stale messages
            // as soon as authorization is restored.
            $supersede = $this->pdo->prepare(
                'UPDATE notification_outbox
                 SET state = \'superseded\', sent_at = :superseded_sent_at
                 WHERE member_id = :member
                   AND (state = \'pending\' OR (state = \'sending\' AND next_attempt_at <= :superseded_due_at))
                   AND id <> :id'
            );
            $supersede->execute([
                'id' => $id,
                'member' => $memberId,
                'superseded_sent_at' => $now,
                'superseded_due_at' => $now,
            ]);
            $this->pdo->prepare($sql)->execute([
                'id' => $id,
                'member' => $memberId,
                'payload' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'next_attempt' => $now,
                'created' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function pendingNotifications(int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, member_id, payload_json, attempts, created_at
             FROM notification_outbox
             WHERE state IN (\'pending\', \'sending\') AND next_attempt_at <= :now
             ORDER BY created_at ASC'
        );
        $statement->execute(['now' => gmdate(DATE_ATOM)]);
        $rows = array_slice($statement->fetchAll(), 0, max(1, min(100, $limit)));
        return array_map(static function (array $row): array {
            $row['results'] = json_decode((string) $row['payload_json'], true) ?: [];
            $row['attempts'] = (int) ($row['attempts'] ?? 0);
            unset($row['payload_json']);
            return $row;
        }, $rows);
    }

    public function claimNotification(string $id): bool
    {
        $now = gmdate(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'UPDATE notification_outbox
             SET state = \'sending\', next_attempt_at = :lease_until
             WHERE id = :id
               AND state IN (\'pending\', \'sending\')
               AND next_attempt_at <= :now'
        );
        $statement->execute([
            'id' => $id,
            'now' => $now,
            'lease_until' => gmdate(DATE_ATOM, time() + self::STALE_LOCK_SECONDS),
        ]);
        return $statement->rowCount() === 1;
    }

    public function finishNotification(string $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE notification_outbox
             SET state = \'sent\', sent_at = :sent, last_error = NULL
             WHERE id = :id'
        );
        $statement->execute(['id' => $id, 'sent' => gmdate(DATE_ATOM)]);
    }

    public function retryNotification(string $id, int $attempts, string $error): void
    {
        $attempts = max(1, $attempts + 1);
        $delay = min(3600, 60 * (2 ** min(6, $attempts - 1)));
        $statement = $this->pdo->prepare(
            'UPDATE notification_outbox
             SET state = \'pending\', attempts = :attempts, next_attempt_at = :next_attempt, last_error = :error
             WHERE id = :id AND state = \'sending\''
        );
        $statement->execute([
            'id' => $id,
            'attempts' => $attempts,
            'next_attempt' => gmdate(DATE_ATOM, time() + $delay),
            'error' => substr($error, 0, 2000),
        ]);
    }

    public function cleanupExpiredData(int $retentionDays): array
    {
        $cutoff = gmdate(DATE_ATOM, time() - max(1, $retentionDays) * 86400);
        $clearPayloads = $this->pdo->prepare(
            'UPDATE sync_items SET payload_json = :empty_value
             WHERE updated_at < :cutoff AND payload_json <> :empty_check'
        );
        $clearPayloads->execute(['empty_value' => '{}', 'empty_check' => '{}', 'cutoff' => $cutoff]);
        $deleteRuns = $this->pdo->prepare(
            'DELETE FROM sync_runs WHERE finished_at IS NOT NULL AND finished_at < :cutoff'
        );
        $deleteRuns->execute(['cutoff' => $cutoff]);
        $deleteAudit = $this->pdo->prepare('DELETE FROM audit_events WHERE created_at < :cutoff');
        $deleteAudit->execute(['cutoff' => $cutoff]);
        $deleteNotifications = $this->pdo->prepare(
            'DELETE FROM notification_outbox WHERE created_at < :cutoff AND state IN (\'sent\', \'superseded\')'
        );
        $deleteNotifications->execute(['cutoff' => $cutoff]);
        $deleteMetadata = $this->pdo->prepare('DELETE FROM bitrix_metadata_cache WHERE expires_at < :cutoff');
        $deleteMetadata->execute(['cutoff' => gmdate(DATE_ATOM, time() - 86400)]);
        return [
            'payloadsCleared' => $clearPayloads->rowCount(),
            'runsDeleted' => $deleteRuns->rowCount(),
            'auditEventsDeleted' => $deleteAudit->rowCount(),
            'notificationsDeleted' => $deleteNotifications->rowCount(),
            'metadataDeleted' => $deleteMetadata->rowCount(),
            'cutoff' => $cutoff,
        ];
    }

    private function providerRow(string $memberId, string $provider): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM provider_connections WHERE member_id = :id AND provider = :provider'
        );
        $statement->execute(['id' => $memberId, 'provider' => $provider]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function replace(string $table, string $key, array $values): void
    {
        $this->replaceComposite($table, [$key], $values);
    }

    private function replaceComposite(string $table, array $keys, array $values): void
    {
        $columns = array_keys($values);
        $updates = array_values(array_diff($columns, $keys));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $where = implode(' AND ', array_map(
                static fn (string $column): string => "{$column} = :where_{$column}",
                $keys,
            ));
            $parameters = [];
            foreach ($updates as $column) {
                $parameters["set_{$column}"] = $values[$column];
            }
            foreach ($keys as $column) {
                $parameters["where_{$column}"] = $values[$column];
            }
            if ($updates !== []) {
                $statement = $this->pdo->prepare(sprintf(
                    'UPDATE %s SET %s WHERE %s',
                    $table,
                    implode(', ', array_map(
                        static fn (string $column): string => "{$column} = :set_{$column}",
                        $updates,
                    )),
                    $where,
                ));
                $statement->execute($parameters);
                if ($statement->rowCount() > 0) {
                    return;
                }
            }
            $exists = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1");
            $exists->execute(array_filter(
                $parameters,
                static fn (string $name): bool => str_starts_with($name, 'where_'),
                ARRAY_FILTER_USE_KEY,
            ));
            if ($exists->fetchColumn() !== false) {
                return;
            }
            $this->pdo->prepare(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $column): string => ":{$column}", $columns)),
            ))->execute($values);
            return;
        }

        $verb = 'INSERT';
        $suffix = match ($driver) {
            'mysql' => ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
                static fn (string $column): string => "{$column} = VALUES({$column})",
                $updates,
            )),
            default => ' ON CONFLICT (' . implode(', ', $keys) . ') DO UPDATE SET ' . implode(', ', array_map(
                static fn (string $column): string => "{$column} = excluded.{$column}",
                $updates,
            )),
        };
        $this->pdo->prepare(sprintf(
            '%s INTO %s (%s) VALUES (%s)%s',
            $verb,
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $column): string => ":{$column}", $columns)),
            $suffix,
        ))->execute($values);
    }
}
