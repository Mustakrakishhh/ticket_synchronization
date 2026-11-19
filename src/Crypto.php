<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final readonly class Crypto
{
    private string $key;

    public function __construct(string $encodedKey)
    {
        $this->key = self::decodeKey($encodedKey);
    }

    public static function decodeKey(string $encodedKey): string
    {
        $value = str_starts_with($encodedKey, 'base64:')
            ? substr($encodedKey, 7)
            : $encodedKey;
        $key = base64_decode($value, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY должен быть base64-кодом ровно 32 случайных байт.');
        }
        return $key;
    }

    public function encrypt(array $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $plaintext = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Не удалось зашифровать секретные данные.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Зашифрованные данные повреждены.');
        }
        $plaintext = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
        );
        if ($plaintext === false) {
            throw new RuntimeException('Не удалось расшифровать секретные данные.');
        }
        $decoded = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Секретные данные имеют неверный формат.');
        }
        return $decoded;
    }
}
