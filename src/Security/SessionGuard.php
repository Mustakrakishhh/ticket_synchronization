<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final readonly class SessionGuard
{
    private const COOKIE = 'b24_travel_sync_session';

    public function __construct(private string $secret)
    {
    }

    public function issue(string $memberId, int $userId, bool $isAdmin): array
    {
        $data = [
            'member' => $memberId,
            'uid' => $userId,
            'admin' => $isAdmin,
            'csrf' => bin2hex(random_bytes(24)),
            'exp' => time() + 7200,
        ];
        $payload = $this->encode(json_encode($data, JSON_THROW_ON_ERROR));
        $signature = $this->encode(hash_hmac('sha256', $payload, $this->secret, true));
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, "{$payload}.{$signature}", [
            'expires' => $data['exp'],
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $secure ? 'None' : 'Lax',
        ]);
        return $data;
    }

    public function requireValid(bool $requireCsrf = false): array
    {
        [$payload, $signature] = array_pad(
            explode('.', (string) ($_COOKIE[self::COOKIE] ?? ''), 2),
            2,
            '',
        );
        $expected = $this->encode(hash_hmac('sha256', $payload, $this->secret, true));
        if ($payload === '' || !hash_equals($expected, $signature)) {
            throw new RuntimeException('Откройте приложение из интерфейса Bitrix24.', 401);
        }
        $data = json_decode($this->decode($payload), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)
            || (int) ($data['exp'] ?? 0) < time()
            || empty($data['member'])
            || (int) ($data['uid'] ?? 0) <= 0
        ) {
            throw new RuntimeException('Сессия приложения истекла.', 401);
        }
        if ($requireCsrf) {
            $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if ($provided === '' || !hash_equals((string) $data['csrf'], $provided)) {
                throw new RuntimeException('Некорректный CSRF-токен.', 403);
            }
        }
        return $data;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Некорректная сессия.', 401);
        }
        return $decoded;
    }
}
