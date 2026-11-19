<?php

declare(strict_types=1);

namespace App;

use App\Bitrix24\Bitrix24Client;
use App\OAuth\OAuthServiceFactory;
use App\Provider\ProviderFactory;
use App\Security\SessionGuard;
use App\Sync\FieldMapper;
use App\Sync\SyncService;

final readonly class Application
{
    public function __construct(
        public Config $config,
        public Database $database,
        public Crypto $crypto,
        public AppRepository $repository,
        public OAuthServiceFactory $oauth,
        public SessionGuard $sessions,
    ) {
    }

    public static function boot(string $projectDir): self
    {
        $config = Config::load($projectDir);
        $config->assertReady();
        $database = new Database($config);
        $crypto = new Crypto($config->encryptionKey);
        $repository = new AppRepository($database->pdo, $crypto);
        return new self(
            $config,
            $database,
            $crypto,
            $repository,
            new OAuthServiceFactory($config, $repository),
            new SessionGuard($config->sessionSecret),
        );
    }

    public function bitrix(string $memberId): Bitrix24Client
    {
        return new Bitrix24Client($this->oauth->fromStoredToken($memberId));
    }

    public function sync(): SyncService
    {
        return new SyncService($this->repository, new ProviderFactory(), new FieldMapper());
    }
}
