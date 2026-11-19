<?php

declare(strict_types=1);

use App\Application;
use App\OAuth\OAuthServiceFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$defaults = [
    'APP_ENV' => 'test',
    'APP_PUBLIC_URL' => 'https://example.test',
    'APP_SESSION_SECRET' => 'test-session-secret-with-at-least-32-characters',
    'APP_ENCRYPTION_KEY' => 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    'BITRIX24_CLIENT_ID' => 'test-client',
    'BITRIX24_CLIENT_SECRET' => 'test-secret',
    'BITRIX24_SCOPE' => 'crm,im,user_basic,userfieldconfig',
    'BITRIX24_OAUTH_SERVER' => 'https://oauth.bitrix24.tech/',
    'DATABASE_DSN' => 'sqlite::memory:',
];
foreach ($defaults as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$memberId = 'oauth-refresh-test-member';
$app->repository->saveTenant($memberId, [
    'access_token' => 'expired-access-token',
    'refresh_token' => 'current-refresh-token',
    'expires' => time() - 60,
    'expires_in' => 3600,
    'domain' => 'example.bitrix24.ru',
    'member_id' => $memberId,
    'client_endpoint' => 'https://example.bitrix24.ru/rest/',
    'server_endpoint' => 'https://stale-oauth.example/rest/',
    'scope' => 'crm,im,user_basic,userfieldconfig',
]);

$requests = [];
$attempt = 0;
$http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$attempt, $memberId): MockResponse {
    $requests[] = compact('method', 'url', 'options');
    $attempt++;
    if ($attempt === 1) {
        return new MockResponse(
            json_encode(['error' => 'temporary', 'error_description' => 'temporary endpoint failure'], JSON_THROW_ON_ERROR),
            ['http_code' => 404],
        );
    }
    return new MockResponse(json_encode([
        'access_token' => 'renewed-access-token',
        'refresh_token' => 'renewed-refresh-token',
        'expires' => time() + 3600,
        'expires_in' => 3600,
        'member_id' => $memberId,
        'client_endpoint' => 'https://example.bitrix24.ru/rest/',
        'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
        // Bitrix24 uses this field for the OAuth server, not the portal.
        'domain' => 'oauth.bitrix24.tech',
        'status' => 'L',
        'scope' => 'crm,im,user_basic,userfieldconfig',
    ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
});

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$factory = new OAuthServiceFactory($app->config, $app->repository, $http);
$placementMember = 'placement-member';
$placement = $factory->fromPlacement([
    'AUTH_ID' => 'placement-access',
    'REFRESH_ID' => 'placement-refresh',
    'AUTH_EXPIRES' => 3600,
    'DOMAIN' => 'placement.bitrix24.ru',
    'member_id' => $placementMember,
    'client_endpoint' => 'https://placement.bitrix24.ru/rest/',
    'server_endpoint' => 'https://oauth.bitrix24.tech/',
    'scope' => 'im crm user_basic userfieldconfig',
], false);
$assert(($placement['record']['client_endpoint'] ?? null) === 'https://placement.bitrix24.ru/rest/', 'Trusted client endpoint was not preserved.');
$assert(($placement['record']['scope'] ?? null) === 'crm,im,user_basic,userfieldconfig', 'Actual placement scope was not normalized.');
$assert(($placement['record']['scope_source'] ?? null) === 'bitrix24', 'Placement scope source was not retained.');
$assert(strlen((string) ($placement['record']['token_fingerprint'] ?? '')) === 12, 'Safe placement token fingerprint was not created.');
$assert($app->repository->tenant($placementMember) === null, 'Unvalidated placement token must not be persisted.');
$unsafeEndpointRejected = false;
try {
    $factory->fromPlacement([
        'AUTH_ID' => 'placement-access',
        'REFRESH_ID' => 'placement-refresh',
        'DOMAIN' => 'placement.bitrix24.ru',
        'member_id' => $placementMember,
        'client_endpoint' => 'https://attacker.example/rest/',
    ], false);
} catch (RuntimeException) {
    $unsafeEndpointRejected = true;
}
$assert($unsafeEndpointRejected, 'Foreign client endpoint must be rejected.');

$factory->fromStoredToken($memberId);
$saved = $app->repository->token($memberId);

$assert(count($requests) === 2, 'Temporary OAuth 404 must be retried once.');
$assert($requests[0]['method'] === 'GET', 'OAuth refresh must use GET.');
$assert(str_starts_with($requests[0]['url'], 'https://oauth.bitrix24.tech/oauth/token/'), 'Configured OAuth root must be used.');
$assert(!str_contains($requests[0]['url'], 'stale-oauth.example'), 'Stored stale OAuth endpoint must not be used.');
$assert(($saved['access_token'] ?? null) === 'renewed-access-token', 'Renewed access token was not saved.');
$assert(($saved['refresh_token'] ?? null) === 'renewed-refresh-token', 'Rotated refresh token was not saved.');
$assert(($saved['client_endpoint'] ?? null) === 'https://example.bitrix24.ru/rest/', 'Canonical portal client endpoint was not restored.');
$assert(($saved['domain'] ?? null) === 'example.bitrix24.ru', 'OAuth server domain must not replace the portal domain.');
$assert(($saved['server_endpoint'] ?? null) === 'https://oauth.bitrix24.tech/rest/', 'Renewed OAuth endpoint was not saved.');
$assert(($saved['scope_source'] ?? null) === 'bitrix24', 'OAuth response scope source was not retained.');
$assert(($saved['token_source'] ?? null) === 'refresh', 'OAuth token source was not recorded.');
$assert(strlen((string) ($saved['token_fingerprint'] ?? '')) === 12, 'Safe refreshed token fingerprint was not created.');

$factory->fromStoredToken($memberId);
$assert(count($requests) === 2, 'Fresh stored token must not be refreshed again.');

echo "OAuth refresh checks passed.\n";
