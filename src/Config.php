<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;

final readonly class Config
{
    public function __construct(
        public string $environment,
        public string $publicUrl,
        public string $sessionSecret,
        public string $encryptionKey,
        public string $distribution,
        public string $clientId,
        public string $clientSecret,
        public string $scope,
        public string $oauthServer,
        public string $databaseDsn,
        public string $databaseUser,
        public string $databasePassword,
        public int $syncIntervalMinutes,
        public string $supportEmail,
        public string $privacyUrl,
        public int $retentionDays,
        public string $healthCheckToken,
    ) {
    }

    public static function load(string $projectDir): self
    {
        $env = $projectDir . '/.env';
        if (is_file($env)) {
            (new Dotenv())->usePutenv()->loadEnv($env);
        }

        $get = static fn (string $name, string $default = ''): string =>
            trim((string) ($_ENV[$name] ?? getenv($name) ?: $default));

        $dsn = $get('DATABASE_DSN', 'sqlite:var/app.sqlite');
        if (str_starts_with($dsn, 'sqlite:')) {
            $file = substr($dsn, 7);
            if ($file !== '' && $file !== ':memory:' && !self::isAbsolute($file)) {
                $dsn = 'sqlite:' . $projectDir . '/' . ltrim($file, '/\\');
            }
        }

        return new self(
            $get('APP_ENV', 'dev'),
            rtrim($get('APP_PUBLIC_URL'), '/'),
            $get('APP_SESSION_SECRET'),
            $get('APP_ENCRYPTION_KEY'),
            $get('APP_DISTRIBUTION', 'local'),
            $get('BITRIX24_CLIENT_ID'),
            $get('BITRIX24_CLIENT_SECRET'),
            $get('BITRIX24_SCOPE', 'crm,im,user_basic,userfieldconfig'),
            rtrim($get('BITRIX24_OAUTH_SERVER', 'https://oauth.bitrix24.tech/'), '/') . '/',
            $dsn,
            $get('DATABASE_USER'),
            $get('DATABASE_PASSWORD'),
            max(5, (int) $get('SYNC_INTERVAL_MINUTES', '15')),
            $get('SUPPORT_EMAIL'),
            $get('PRIVACY_URL'),
            max(1, (int) $get('DATA_RETENTION_DAYS', '90')),
            $get('HEALTH_CHECK_TOKEN'),
        );
    }

    public function assertReady(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Заполните BITRIX24_CLIENT_ID и BITRIX24_CLIENT_SECRET.');
        }
        if (!filter_var($this->publicUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('APP_PUBLIC_URL должен быть публичным HTTPS URL.');
        }
        if (!str_starts_with($this->publicUrl, 'https://') && $this->environment !== 'dev') {
            throw new RuntimeException('В production APP_PUBLIC_URL обязан использовать HTTPS.');
        }
        if (strlen($this->sessionSecret) < 32) {
            throw new RuntimeException('APP_SESSION_SECRET должен содержать минимум 32 символа.');
        }
        if (!in_array($this->distribution, ['local', 'marketplace'], true)) {
            throw new RuntimeException('APP_DISTRIBUTION должен быть local или marketplace.');
        }
        if ($this->distribution === 'marketplace' && !str_starts_with($this->databaseDsn, 'mysql:')) {
            throw new RuntimeException('Marketplace mode requires MySQL in DATABASE_DSN.');
        }
        if ($this->environment === 'prod' && strlen($this->healthCheckToken) < 24) {
            throw new RuntimeException('Production requires HEALTH_CHECK_TOKEN with at least 24 characters.');
        }
        Crypto::decodeKey($this->encryptionKey);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
