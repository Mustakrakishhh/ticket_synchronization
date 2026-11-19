<?php

declare(strict_types=1);

use App\MigrationRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(
    'CREATE TABLE tenant_settings (
        member_id VARCHAR(64) PRIMARY KEY,
        entity_type_id INTEGER NULL,
        settings_json TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 0,
        updated_at VARCHAR(40) NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE sync_items (
        member_id VARCHAR(64) NOT NULL,
        provider VARCHAR(32) NOT NULL,
        external_id VARCHAR(191) NOT NULL,
        bitrix_item_id INTEGER NULL,
        checksum VARCHAR(64) NULL,
        state VARCHAR(32) NOT NULL,
        payload_json TEXT NOT NULL,
        last_error TEXT NULL,
        updated_at VARCHAR(40) NOT NULL,
        PRIMARY KEY (member_id, provider, external_id)
    )'
);
$pdo->exec(
    "INSERT INTO tenant_settings VALUES ('legacy-member', 1258, '{}', 1, '2026-08-01T00:00:00Z')"
);
$pdo->exec(
    "INSERT INTO sync_items VALUES (
        'legacy-member', 'aviasales', 'air:old-1', 9001, 'checksum',
        'synced', '{}', NULL, '2026-08-01T00:00:00Z'
    )"
);

(new MigrationRunner($pdo))->migrate();

$item = $pdo->query("SELECT * FROM sync_items WHERE external_id = 'air:old-1'")->fetch();
$versions = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
$syncRunIndexes = array_column($pdo->query('PRAGMA index_list(sync_runs)')->fetchAll(), 'name');
if (!is_array($item)
    || (int) ($item['entity_type_id'] ?? 0) !== 1258
    || (int) ($item['bitrix_item_id'] ?? 0) !== 9001
    || array_map('intval', $versions) !== [1, 2, 3, 4, 5, 6, 7]
    || $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'bitrix_metadata_cache'")->fetchColumn() !== 'bitrix_metadata_cache'
    || !in_array('idx_sync_runs_member_provider_started', $syncRunIndexes, true)
) {
    throw new RuntimeException('Legacy SQLite migration did not preserve sync state.');
}

echo "Legacy SQLite migration checks passed.\n";
