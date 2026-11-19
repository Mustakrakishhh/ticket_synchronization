<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Tests set their own credentials and use only an in-memory database.
// Never load the application's .env or attempt to connect to its production DB.
if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'test'
    || ($_ENV['DATABASE_DSN'] ?? getenv('DATABASE_DSN')) !== 'sqlite::memory:'
) {
    throw new RuntimeException('Test bootstrap requires APP_ENV=test and DATABASE_DSN=sqlite::memory:.');
}
$isolatedRoot = __DIR__ . '/isolated-runtime';
if (is_dir($isolatedRoot)) {
    throw new RuntimeException('The isolated test configuration path must not exist.');
}
return App\Application::boot($isolatedRoot);
