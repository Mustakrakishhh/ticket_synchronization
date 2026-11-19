<?php

declare(strict_types=1);

use App\Application;
use App\MigrationRunner;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['source:', 'confirm']);
$sourcePath = trim((string) ($options['source'] ?? ''));
if (!isset($options['confirm']) || $sourcePath === '') {
    fwrite(STDERR, "Usage: php bin/migrate-sqlite-to-mysql.php --source=/absolute/path/app.sqlite --confirm\n");
    exit(2);
}
if ((string) $app->database->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    fwrite(STDERR, "DATABASE_DSN must point to the target MySQL database.\n");
    exit(2);
}

$resolvedSource = realpath($sourcePath);
if ($resolvedSource === false || !is_file($resolvedSource)) {
    fwrite(STDERR, "SQLite source file was not found.\n");
    exit(2);
}
$backupPath = $resolvedSource . '.backup-' . gmdate('Ymd-His');
if (!copy($resolvedSource, $backupPath)) {
    fwrite(STDERR, "Could not create SQLite backup.\n");
    exit(1);
}

$source = new PDO('sqlite:' . $resolvedSource, options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
(new MigrationRunner($source))->migrate();

$target = $app->database->pdo;
$existingTenants = (int) $target->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
if ($existingTenants !== 0) {
    fwrite(STDERR, "Target MySQL database is not empty; migration was cancelled.\n");
    exit(1);
}

$tables = [
    'tenants',
    'provider_connections',
    'tenant_settings',
    'field_mappings',
    'sync_items',
    'sync_runs',
    'sync_cursors',
    'tenant_users',
    'audit_events',
];
$counts = [];
$target->beginTransaction();
try {
    foreach ($tables as $table) {
        $rows = $source->query("SELECT * FROM {$table}")->fetchAll();
        $counts[$table] = count($rows);
        if ($rows === []) {
            continue;
        }
        $columns = array_keys($rows[0]);
        $insert = $target->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)),
        ));
        foreach ($rows as $row) {
            if ($table === 'sync_runs' && in_array($row['state'] ?? null, ['queued', 'running'], true)) {
                $row['state'] = 'failed';
                $row['error_text'] = 'Stopped during SQLite to MySQL migration.';
                $row['finished_at'] = gmdate(DATE_ATOM);
            }
            $insert->execute($row);
        }
    }
    $target->commit();
} catch (Throwable $exception) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, "Migration failed: {$exception->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, json_encode([
    'state' => 'success',
    'backup' => $backupPath,
    'copied' => $counts,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
