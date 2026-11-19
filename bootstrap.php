<?php

declare(strict_types=1);

$projectDir = __DIR__;
$autoload = $projectDir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException(
        'Не найден vendor/autoload.php. Выполните composer install.'
    );
}

require_once $autoload;

return App\Application::boot($projectDir);
