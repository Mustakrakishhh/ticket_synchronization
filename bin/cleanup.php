<?php

declare(strict_types=1);

use App\Application;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';

fwrite(STDOUT, json_encode(
    $app->repository->cleanupExpiredData($app->config->retentionDays),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . PHP_EOL);
