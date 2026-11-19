<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public readonly PDO $pdo;

    public function __construct(Config $config)
    {
        if (str_starts_with($config->databaseDsn, 'sqlite:')) {
            $directory = dirname(substr($config->databaseDsn, 7));
            if (!is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
        }
        $this->pdo = new PDO(
            $config->databaseDsn,
            $config->databaseUser !== '' ? $config->databaseUser : null,
            $config->databasePassword !== '' ? $config->databasePassword : null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
        (new MigrationRunner($this->pdo))->migrate();
    }
}
