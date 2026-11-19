<?php

declare(strict_types=1);

namespace App\OAuth;

final class InstallationRequest
{
    public static function fromGlobals(): array
    {
        $payload = array_merge($_GET, $_POST);
        if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }
        $auth = isset($payload['auth']) && is_array($payload['auth']) ? $payload['auth'] : [];
        $first = static function (array $keys) use ($payload, $auth): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $payload)) {
                    return $payload[$key];
                }
                if (array_key_exists($key, $auth)) {
                    return $auth[$key];
                }
            }
            return null;
        };
        return array_filter([
            'AUTH_ID' => $first(['AUTH_ID', 'access_token']),
            'REFRESH_ID' => $first(['REFRESH_ID', 'refresh_token']),
            'AUTH_EXPIRES' => $first(['AUTH_EXPIRES', 'expires_in', 'expires']),
            'DOMAIN' => $first(['DOMAIN', 'domain']),
            'member_id' => $first(['member_id', 'MEMBER_ID']),
            'client_endpoint' => $first(['client_endpoint', 'CLIENT_ENDPOINT']),
            'server_endpoint' => $first(['server_endpoint', 'SERVER_ENDPOINT']),
            'scope' => $first(['scope', 'SCOPE']),
            'application_token' => $first(['application_token', 'APPLICATION_TOKEN']),
            'OAUTH_RETRY' => $first(['OAUTH_RETRY']),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
