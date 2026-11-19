<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final readonly class MigrationRunner
{
    private string $driver;

    public function __construct(private PDO $pdo)
    {
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException("Unsupported database driver: {$this->driver}.");
        }
    }

    public function migrate(): void
    {
        $this->createMigrationTable();
        foreach ([
            1 => fn () => $this->createInitialSchema(),
            2 => fn () => $this->scopeSyncItemsByEntityType(),
            3 => fn () => $this->createTenantUsersAndAuditLog(),
            4 => fn () => $this->createQueueGuardsAndIndexes(),
            5 => fn () => $this->createNotificationOutbox(),
            6 => fn () => $this->createBitrixMetadataCache(),
            7 => fn () => $this->createSyncRunLookupIndex(),
        ] as $version => $migration) {
            if ($this->isApplied($version)) {
                continue;
            }
            $transactional = $this->driver === 'sqlite';
            if ($transactional) {
                $this->pdo->beginTransaction();
            }
            try {
                $migration();
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied)'
                );
                $statement->execute(['version' => $version, 'applied' => gmdate(DATE_ATOM)]);
                if ($transactional && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Database migration {$version} failed: {$exception->getMessage()}", 0, $exception);
            }
        }
    }

    private function createMigrationTable(): void
    {
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version INTEGER PRIMARY KEY, applied_at VARCHAR(40) NOT NULL)'
            . $suffix
        );
    }

    private function isApplied(int $version): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $statement->execute(['version' => $version]);
        return $statement->fetchColumn() !== false;
    }

    private function createInitialSchema(): void
    {
        $idType = $this->driver === 'mysql' ? 'BIGINT' : 'INTEGER';
        foreach ([
            'CREATE TABLE IF NOT EXISTS tenants (
                member_id VARCHAR(64) PRIMARY KEY,
                domain VARCHAR(255) NOT NULL,
                client_endpoint VARCHAR(500) NOT NULL,
                oauth_payload TEXT NOT NULL,
                application_token_hash VARCHAR(255) NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'active\',
                installed_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS provider_connections (
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                credentials_payload TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                verified_at VARCHAR(40) NULL,
                last_error TEXT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, provider)
            )',
            'CREATE TABLE IF NOT EXISTS tenant_settings (
                member_id VARCHAR(64) PRIMARY KEY,
                entity_type_id INTEGER NULL,
                settings_json TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 0,
                updated_at VARCHAR(40) NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS field_mappings (
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                source_field VARCHAR(191) NOT NULL,
                target_field VARCHAR(191) NOT NULL,
                transform_name VARCHAR(64) NULL,
                PRIMARY KEY (member_id, provider, source_field)
            )',
            "CREATE TABLE IF NOT EXISTS sync_items (
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                entity_type_id INTEGER NOT NULL DEFAULT 0,
                external_id VARCHAR(191) NOT NULL,
                bitrix_item_id {$idType} NULL,
                checksum VARCHAR(64) NULL,
                state VARCHAR(32) NOT NULL,
                payload_json TEXT NOT NULL,
                last_error TEXT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, provider, entity_type_id, external_id)
            )",
            'CREATE TABLE IF NOT EXISTS sync_runs (
                id VARCHAR(64) PRIMARY KEY,
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                state VARCHAR(32) NOT NULL,
                stats_json TEXT NOT NULL,
                error_text TEXT NULL,
                started_at VARCHAR(40) NOT NULL,
                finished_at VARCHAR(40) NULL
            )',
            'CREATE TABLE IF NOT EXISTS sync_cursors (
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                cursor_value VARCHAR(191) NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, provider)
            )',
            'CREATE TABLE IF NOT EXISTS sync_locks (
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                run_id VARCHAR(64) NOT NULL,
                locked_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, provider)
            )',
        ] as $sql) {
            $suffix = $this->driver === 'mysql'
                ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                : '';
            $this->pdo->exec($sql . $suffix);
        }
    }

    private function scopeSyncItemsByEntityType(): void
    {
        $expectedPrimaryKey = ['member_id', 'provider', 'entity_type_id', 'external_id'];
        $hasEntityType = $this->columnExists('sync_items', 'entity_type_id');
        if ($hasEntityType && $this->primaryKeyColumns('sync_items') === $expectedPrimaryKey) {
            return;
        }
        if ($this->driver === 'mysql') {
            if (!$hasEntityType) {
                $this->pdo->exec('ALTER TABLE sync_items ADD entity_type_id INTEGER NOT NULL DEFAULT 0 AFTER provider');
                $this->pdo->exec(
                    'UPDATE sync_items i LEFT JOIN tenant_settings s ON s.member_id = i.member_id '
                    . 'SET i.entity_type_id = COALESCE(s.entity_type_id, 0)'
                );
            }
            $this->pdo->exec(
                'ALTER TABLE sync_items DROP PRIMARY KEY, '
                . 'ADD PRIMARY KEY (member_id, provider, entity_type_id, external_id)'
            );
            return;
        }

        $this->pdo->exec('ALTER TABLE sync_items RENAME TO sync_items_legacy');
        $this->pdo->exec(
            'CREATE TABLE sync_items (
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                entity_type_id INTEGER NOT NULL DEFAULT 0,
                external_id VARCHAR(191) NOT NULL,
                bitrix_item_id INTEGER NULL,
                checksum VARCHAR(64) NULL,
                state VARCHAR(32) NOT NULL,
                payload_json TEXT NOT NULL,
                last_error TEXT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, provider, entity_type_id, external_id)
            )'
        );
        $this->pdo->exec(
            'INSERT INTO sync_items (
                member_id, provider, entity_type_id, external_id, bitrix_item_id,
                checksum, state, payload_json, last_error, updated_at
            ) SELECT i.member_id, i.provider, '
                . ($hasEntityType ? 'i.entity_type_id' : 'COALESCE(s.entity_type_id, 0)')
                . ', i.external_id,
                i.bitrix_item_id, i.checksum, i.state, i.payload_json, i.last_error, i.updated_at
              FROM sync_items_legacy i
              LEFT JOIN tenant_settings s ON s.member_id = i.member_id'
        );
        $this->pdo->exec('DROP TABLE sync_items_legacy');
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->driver === 'sqlite') {
            $rows = $this->pdo->query("PRAGMA table_info({$table})")->fetchAll();
            return in_array($column, array_column($rows, 'name'), true);
        }
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<string> */
    private function primaryKeyColumns(string $table): array
    {
        if ($this->driver === 'sqlite') {
            $rows = $this->pdo->query("PRAGMA table_info({$table})")->fetchAll();
            usort($rows, static fn (array $left, array $right): int => (int) $left['pk'] <=> (int) $right['pk']);
            return array_values(array_map(
                static fn (array $row): string => (string) $row['name'],
                array_filter($rows, static fn (array $row): bool => (int) $row['pk'] > 0),
            ));
        }
        $statement = $this->pdo->prepare(
            "SELECT column_name FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE() AND table_name = :table AND constraint_name = 'PRIMARY'
             ORDER BY ordinal_position"
        );
        $statement->execute(['table' => $table]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function createTenantUsersAndAuditLog(): void
    {
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tenant_users (
                member_id VARCHAR(64) NOT NULL,
                user_id INTEGER NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                last_seen_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, user_id)
            )' . $suffix
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_events (
                id VARCHAR(64) PRIMARY KEY,
                member_id VARCHAR(64) NOT NULL,
                user_id INTEGER NULL,
                action VARCHAR(100) NOT NULL,
                context_json TEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL
            )' . $suffix
        );
    }

    private function createQueueGuardsAndIndexes(): void
    {
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS queue_guards (
                member_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                touched_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, provider)
            )' . $suffix
        );
        foreach ([
            'idx_sync_runs_queue' => ['sync_runs', 'state, started_at'],
            'idx_sync_runs_member' => ['sync_runs', 'member_id, started_at'],
            'idx_audit_member' => ['audit_events', 'member_id, created_at'],
        ] as $index => [$table, $columns]) {
            if (!$this->indexExists($table, $index)) {
                $this->pdo->exec("CREATE INDEX {$index} ON {$table} ({$columns})");
            }
        }
    }

    private function createNotificationOutbox(): void
    {
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS notification_outbox (
                id VARCHAR(64) PRIMARY KEY,
                member_id VARCHAR(64) NOT NULL,
                payload_json TEXT NOT NULL,
                state VARCHAR(32) NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at VARCHAR(40) NOT NULL,
                last_error TEXT NULL,
                created_at VARCHAR(40) NOT NULL,
                sent_at VARCHAR(40) NULL
            )' . $suffix
        );
        if (!$this->indexExists('notification_outbox', 'idx_notification_outbox_pending')) {
            $this->pdo->exec(
                'CREATE INDEX idx_notification_outbox_pending '
                . 'ON notification_outbox (state, next_attempt_at)'
            );
        }
    }

    private function createBitrixMetadataCache(): void
    {
        $payloadType = $this->driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS bitrix_metadata_cache (
                member_id VARCHAR(64) NOT NULL,
                cache_key VARCHAR(191) NOT NULL,
                payload_json {$payloadType} NOT NULL,
                expires_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (member_id, cache_key)
            )" . $suffix
        );
        if (!$this->indexExists('bitrix_metadata_cache', 'idx_bitrix_metadata_expiry')) {
            $this->pdo->exec(
                'CREATE INDEX idx_bitrix_metadata_expiry ON bitrix_metadata_cache (expires_at)'
            );
        }
    }

    private function createSyncRunLookupIndex(): void
    {
        if (!$this->indexExists('sync_runs', 'idx_sync_runs_member_provider_started')) {
            $this->pdo->exec(
                'CREATE INDEX idx_sync_runs_member_provider_started '
                . 'ON sync_runs (member_id, provider, started_at)'
            );
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if ($this->driver === 'sqlite') {
            $rows = $this->pdo->query("PRAGMA index_list({$table})")->fetchAll();
            return in_array($index, array_column($rows, 'name'), true);
        }
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $statement->execute(['table' => $table, 'index' => $index]);
        return $statement->fetchColumn() !== false;
    }
}
