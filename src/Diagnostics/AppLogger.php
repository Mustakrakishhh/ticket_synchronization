<?php

declare(strict_types=1);

namespace App\Diagnostics;

final readonly class AppLogger
{
    public function __construct(private string $path)
    {
    }

    public function write(string $event, array $context = []): void
    {
        $record = [
            'timestamp' => gmdate(DATE_ATOM),
            'event' => $event,
        ] + $this->sanitize($context);
        try {
            $line = json_encode(
                $record,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
            file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Diagnostics must never break the application request.
        }
    }

    private function sanitize(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            $safeKey = (string) $key;
            if (preg_match('/token|secret|password|credential|authorization/i', $safeKey)) {
                $result[$safeKey] = '[redacted]';
                continue;
            }
            if (is_string($value)) {
                $result[$safeKey] = substr($this->redactString($value), 0, 2000);
            } elseif (is_scalar($value) || $value === null) {
                $result[$safeKey] = $value;
            } elseif (is_array($value)) {
                $result[$safeKey] = $this->sanitize($value);
            } else {
                $result[$safeKey] = get_debug_type($value);
            }
        }
        return $result;
    }

    private function redactString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace(
            '/(access_token|refresh_token|client_secret|api[_-]?key|password|authorization)(\s*[:=]\s*)([^\s&;,]+)/i',
            '$1$2[redacted]',
            $value,
        ) ?? $value;
        return $value;
    }
}
