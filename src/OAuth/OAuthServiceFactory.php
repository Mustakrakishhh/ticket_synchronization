<?php

declare(strict_types=1);

namespace App\OAuth;

use App\AppRepository;
use App\Config;
use Bitrix24\SDK\Core\Batch;
use Bitrix24\SDK\Core\BulkItemsReader\BulkItemsReaderBuilder;
use Bitrix24\SDK\Core\CoreBuilder;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Credentials;
use Bitrix24\SDK\Core\Credentials\Endpoints;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Bitrix24\SDK\Services\ServiceBuilder;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OAuthServiceFactory
{
    private HttpClientInterface $oauthHttp;

    public function __construct(
        private Config $config,
        private AppRepository $repository,
        ?HttpClientInterface $oauthHttp = null,
    ) {
        $this->oauthHttp = $oauthHttp ?? HttpClient::create([
            'timeout' => 15,
            'max_duration' => 30,
        ]);
    }

    public function fromStoredToken(string $memberId): ServiceBuilder
    {
        $record = $this->repository->token($memberId);
        if ($this->shouldRefresh($record)) {
            $record = $this->refreshStoredToken($memberId, $record);
        }
        return $this->fromRecord($memberId, $record);
    }

    public function fromPlacement(array $request, bool $persist = true): array
    {
        foreach (['AUTH_ID', 'REFRESH_ID', 'DOMAIN', 'member_id'] as $required) {
            if (empty($request[$required])) {
                throw new RuntimeException("Bitrix24 не передал {$required}.");
            }
        }
        if ($this->config->distribution === 'marketplace' && empty($request['application_token'])) {
            throw new RuntimeException('Bitrix24 did not provide application_token for Marketplace installation.');
        }
        $raw = (int) ($request['AUTH_EXPIRES'] ?? 3600);
        if ($raw > 10_000_000_000) {
            $raw = (int) floor($raw / 1000);
        }
        $expires = $raw > time() ? $raw : time() + max(60, $raw);
        $record = [
            'access_token' => (string) $request['AUTH_ID'],
            'refresh_token' => (string) $request['REFRESH_ID'],
            'expires' => $expires,
            'expires_in' => max(60, $expires - time()),
            'domain' => $this->normalizeDomain((string) $request['DOMAIN']),
            'member_id' => (string) $request['member_id'],
            'client_endpoint' => $this->clientEndpoint($request),
            'server_endpoint' => $this->serverEndpoint($request),
            'scope' => $this->normalizeScope((string) ($request['scope'] ?? $this->config->scope)),
            'scope_source' => !empty($request['scope']) ? 'bitrix24' : 'configuration',
            'token_fingerprint' => $this->tokenFingerprint((string) $request['AUTH_ID']),
            'token_source' => 'placement',
            'application_token' => (string) ($request['application_token'] ?? ''),
        ];
        if ($persist) {
            $this->repository->saveTenant((string) $request['member_id'], $record);
        }
        return [
            'memberId' => (string) $request['member_id'],
            'record' => $record,
            'service' => $this->fromRecord((string) $request['member_id'], $record),
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        $host = strtolower((string) parse_url('https://' . $domain, PHP_URL_HOST));
        if ($domain === ''
            || $host !== $domain
            || filter_var('https://' . $domain, FILTER_VALIDATE_URL) === false
        ) {
            throw new RuntimeException('Bitrix24 передал некорректный DOMAIN.');
        }
        if ($this->config->distribution === 'marketplace'
            && preg_match('/^[a-z0-9-]+\.bitrix24\.[a-z.]+$/i', $domain) !== 1
        ) {
            throw new RuntimeException('Marketplace accepts only canonical Bitrix24 cloud domains.');
        }
        return $domain;
    }

    private function clientEndpoint(array $request): string
    {
        $domain = $this->normalizeDomain((string) $request['DOMAIN']);
        $endpoint = trim((string) ($request['client_endpoint'] ?? ''));
        if ($endpoint === '') {
            return $this->canonicalClientEndpoint($domain);
        }
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        if ($scheme !== 'https' || $host !== $domain) {
            throw new RuntimeException('Bitrix24 передал небезопасный client_endpoint.');
        }
        // Never persist a path, query, credentials or port supplied by the request.
        // Bitrix24 REST has a canonical endpoint on the verified portal domain.
        return $this->canonicalClientEndpoint($domain);
    }

    private function canonicalClientEndpoint(string $domain): string
    {
        return "https://{$domain}/rest/";
    }

    private function serverEndpoint(array $request): string
    {
        $endpoint = trim((string) ($request['server_endpoint'] ?? ''));
        if ($endpoint === '') {
            return $this->config->oauthServer;
        }
        $expectedHost = strtolower((string) parse_url($this->config->oauthServer, PHP_URL_HOST));
        if (strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https'
            || strtolower((string) parse_url($endpoint, PHP_URL_HOST)) !== $expectedHost
        ) {
            throw new RuntimeException('Bitrix24 передал небезопасный server_endpoint.');
        }
        return rtrim($endpoint, '/') . '/';
    }

    private function fromRecord(string $memberId, array $record): ServiceBuilder
    {
        $this->config->assertReady();
        $record['domain'] = $this->normalizeDomain((string) ($record['domain'] ?? ''));
        $record['client_endpoint'] = $this->clientEndpoint([
            'DOMAIN' => $record['domain'],
            'client_endpoint' => $record['client_endpoint'] ?? '',
        ]);
        $record['server_endpoint'] = $this->serverEndpoint([
            'server_endpoint' => $record['server_endpoint'] ?? '',
        ]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            AuthTokenRenewedEvent::class,
            function (AuthTokenRenewedEvent $event) use ($memberId, $record): void {
                $renewed = $event->getRenewedToken();
                if (!hash_equals($memberId, $renewed->memberId)) {
                    throw new RuntimeException('OAuth refresh returned another member_id.');
                }
                [$renewedDomain, $renewedClientEndpoint] = $this->portalIdentityFromClientEndpoint(
                    (string) $renewed->clientEndpoint,
                    (string) $record['domain'],
                );
                $this->repository->saveTenant($memberId, array_merge($record, [
                    'access_token' => $renewed->authToken->accessToken,
                    'refresh_token' => $renewed->authToken->refreshToken,
                    'expires' => $renewed->authToken->expires,
                    'expires_in' => $renewed->authToken->expiresIn,
                    'member_id' => $renewed->memberId,
                    'client_endpoint' => $renewedClientEndpoint,
                    'server_endpoint' => $this->serverEndpoint([
                        'server_endpoint' => $renewed->serverEndpoint,
                    ]),
                    'domain' => $renewedDomain,
                    'token_fingerprint' => $this->tokenFingerprint($renewed->authToken->accessToken),
                    'token_source' => 'sdk_refresh',
                ]));
            },
        );
        $profile = new ApplicationProfile(
            $this->config->clientId,
            $this->config->clientSecret,
            Scope::initFromString($this->config->scope),
        );
        $authToken = new AuthToken(
            (string) $record['access_token'],
            !empty($record['refresh_token']) ? (string) $record['refresh_token'] : null,
            (int) ($record['expires'] ?? time() + 3600),
            isset($record['expires_in']) ? (int) $record['expires_in'] : null,
        );
        $credentials = Credentials::createFromOAuth(
            $authToken,
            $profile,
            new Endpoints(
                (string) $record['client_endpoint'],
                (string) ($record['server_endpoint'] ?? $this->config->oauthServer),
            ),
        );
        $logger = new NullLogger();
        $core = (new CoreBuilder())
            ->withEventDispatcher($dispatcher)
            ->withLogger($logger)
            ->withHttpClient(HttpClient::create([
                'http_version' => '2.0',
                'timeout' => 15,
                'max_duration' => 30,
            ]))
            ->withCredentials($credentials)
            ->build();
        $batch = new Batch($core, $logger);

        return new ServiceBuilder(
            $core,
            $batch,
            (new BulkItemsReaderBuilder($core, $batch, $logger))->build(),
            $logger,
        );
    }

    private function shouldRefresh(array $record): bool
    {
        // Refresh only when the token has effectively expired. The SDK remains
        // the fallback for an expired_token response if a request crosses this
        // boundary while external provider data is being processed.
        return (int) ($record['expires'] ?? 0) <= time() + 30;
    }

    private function refreshStoredToken(string $memberId, array $record): array
    {
        $refreshToken = trim((string) ($record['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new RuntimeException(
                'OAuth-токен Bitrix24 истёк, но refresh token отсутствует. Переустановите локальное приложение один раз.'
            );
        }

        $url = rtrim($this->config->oauthServer, '/') . '/oauth/token/';
        $lastError = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->oauthHttp->request('GET', $url, [
                    'query' => [
                        'grant_type' => 'refresh_token',
                        'client_id' => $this->config->clientId,
                        'client_secret' => $this->config->clientSecret,
                        'refresh_token' => $refreshToken,
                    ],
                ]);
                $status = $response->getStatusCode();
                $payload = $response->toArray(false);
                if ($status !== 200 || !is_array($payload) || empty($payload['access_token'])) {
                    $description = trim((string) ($payload['error_description'] ?? $payload['error'] ?? 'сервер не передал описание ошибки'));
                    throw new RuntimeException("OAuth Bitrix24 вернул HTTP {$status}: {$description}", $status);
                }

                $expiresIn = max(60, (int) ($payload['expires_in'] ?? 3600));
                $expires = (int) ($payload['expires'] ?? 0);
                if ($expires <= time()) {
                    $expires = time() + $expiresIn;
                }
                $responseMemberId = (string) ($payload['member_id'] ?? $memberId);
                if (!hash_equals($memberId, $responseMemberId)) {
                    throw new RuntimeException('OAuth refresh returned another member_id.');
                }
                // In an OAuth refresh response `domain` identifies the OAuth server
                // (for example oauth.bitrix24.tech), not the customer portal. The
                // portal is identified by client_endpoint. Mixing the two makes the
                // application call CRM methods on the OAuth server after one hour.
                [$renewedDomain, $renewedClientEndpoint] = $this->portalIdentityFromClientEndpoint(
                    (string) ($payload['client_endpoint'] ?? ''),
                    (string) ($record['domain'] ?? ''),
                );
                $renewed = array_merge($record, [
                    'access_token' => (string) $payload['access_token'],
                    'refresh_token' => (string) ($payload['refresh_token'] ?? $refreshToken),
                    'expires' => $expires,
                    'expires_in' => $expiresIn,
                    'member_id' => $responseMemberId,
                    'client_endpoint' => $renewedClientEndpoint,
                    'server_endpoint' => $this->serverEndpoint([
                        'server_endpoint' => $payload['server_endpoint'] ?? $this->config->oauthServer,
                    ]),
                    'domain' => $renewedDomain,
                    'scope' => $this->normalizeScope((string) ($payload['scope'] ?? $record['scope'] ?? $this->config->scope)),
                    'scope_source' => isset($payload['scope']) ? 'bitrix24' : ($record['scope_source'] ?? 'configuration'),
                    'token_fingerprint' => $this->tokenFingerprint((string) $payload['access_token']),
                    'token_source' => 'refresh',
                ]);
                $this->saveRenewedRecord($memberId, $renewed);
                try {
                    $this->repository->audit($memberId, null, 'oauth.refreshed', [
                        'tokenFingerprint' => $renewed['token_fingerprint'],
                        'expires' => $renewed['expires'],
                        'scope' => $renewed['scope'],
                        'scopeSource' => $renewed['scope_source'],
                        'portalDomain' => $renewedDomain,
                        'oauthDomain' => (string) ($payload['domain'] ?? ''),
                    ]);
                } catch (\Throwable) {
                    // A diagnostic write must never invalidate an already rotated token pair.
                }
                return $renewed;
            } catch (\Throwable $exception) {
                $lastError = $exception;
                $status = (int) $exception->getCode();
                $isTemporary = $status === 0 || $status === 404 || $status === 429 || $status >= 500;
                if (!$isTemporary || $attempt >= 3) {
                    break;
                }
                usleep(250_000 * $attempt);
            }
        }

        // Another PHP process may have refreshed and rotated the same token first.
        // Reuse its fresh record instead of requiring browser authorization.
        try {
            $current = $this->repository->token($memberId);
            if (($current['refresh_token'] ?? null) !== $refreshToken && !$this->shouldRefresh($current)) {
                return $current;
            }
        } catch (\Throwable) {
            // Preserve the original OAuth failure below.
        }

        throw new RuntimeException(
            'Не удалось автоматически обновить OAuth-токен Bitrix24: '
                . ($lastError?->getMessage() ?? 'неизвестная ошибка'),
            previous: $lastError,
        );
    }

    private function saveRenewedRecord(string $memberId, array $record): void
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->repository->saveTenant($memberId, $record);
                return;
            } catch (\Throwable $exception) {
                $lastError = $exception;
                if (!str_contains(strtoupper($exception->getMessage()), 'DATABASE IS LOCKED') || $attempt >= 5) {
                    break;
                }
                usleep(100_000 * $attempt);
            }
        }

        throw new RuntimeException(
            'Новый OAuth-токен Bitrix24 получен, но не сохранён в базе: '
                . ($lastError?->getMessage() ?? 'неизвестная ошибка'),
            409,
            $lastError,
        );
    }

    private function normalizeScope(string $scope): string
    {
        $items = preg_split('/[\s,]+/', strtolower(trim($scope))) ?: [];
        $items = array_values(array_unique(array_filter(array_map('trim', $items))));
        sort($items);
        return implode(',', $items);
    }

    private function tokenFingerprint(string $accessToken): string
    {
        return substr(hash('sha256', $accessToken), 0, 12);
    }

    /** @return array{0: string, 1: string} */
    private function portalIdentityFromClientEndpoint(string $endpoint, string $fallbackDomain): array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            $domain = $this->normalizeDomain($fallbackDomain);
            return [$domain, $this->canonicalClientEndpoint($domain)];
        }

        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $path = rtrim((string) parse_url($endpoint, PHP_URL_PATH), '/');
        $hasUnsafeParts = parse_url($endpoint, PHP_URL_USER) !== null
            || parse_url($endpoint, PHP_URL_PASS) !== null
            || parse_url($endpoint, PHP_URL_PORT) !== null
            || parse_url($endpoint, PHP_URL_QUERY) !== null
            || parse_url($endpoint, PHP_URL_FRAGMENT) !== null;
        if ($scheme !== 'https' || $host === '' || $path !== '/rest' || $hasUnsafeParts) {
            throw new RuntimeException('OAuth Bitrix24 передал небезопасный client_endpoint.');
        }

        $domain = $this->normalizeDomain($host);
        return [$domain, $this->canonicalClientEndpoint($domain)];
    }
}
