<?php

declare(strict_types=1);

namespace App\Sync;

final class FieldMapper
{
    public function map(array $source, array $mappings): array
    {
        $fields = [];
        foreach ($mappings as $mapping) {
            $sourceField = (string) ($mapping['sourceField'] ?? '');
            $targetField = (string) ($mapping['targetField'] ?? '');
            if ($sourceField === '' || $targetField === '') {
                continue;
            }
            if (in_array(($mapping['transform'] ?? ''), ['file', 'file_multiple'], true)
                || in_array($sourceField, ['ticket_file', 'additional_service_files'], true)
            ) {
                continue;
            }
            $value = $this->get($source, $sourceField, ($mapping['transform'] ?? '') === 'string_multiple');
            if ($value === null || $value === '') {
                continue;
            }
            $fields[$targetField] = $this->transform(
                $value,
                (string) ($mapping['transform'] ?? ''),
                (string) ($source['currency'] ?? 'RUB'),
            );
        }
        return $fields;
    }

    public function title(string $template, array $source): string
    {
        $title = preg_replace_callback(
            '/\{([a-zA-Z0-9_.]+)\}/',
            fn (array $matches): string => (string) ($this->get($source, $matches[1]) ?? ''),
            $template,
        );
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $title));
        if ($title === '') {
            return 'Travel Sync: ' . (string) ($source['external_id'] ?? 'услуга');
        }
        return function_exists('mb_substr') ? mb_substr($title, 0, 255) : substr($title, 0, 255);
    }

    private function get(array $source, string $path, bool $preserveArray = false): mixed
    {
        $value = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return is_array($value) && !$preserveArray
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $value;
    }

    private function transform(mixed $value, string $transform, string $currency): mixed
    {
        return match ($transform) {
            'string' => (string) $value,
            'string_multiple' => array_values(array_unique(array_map('strval', is_array($value) ? $value : [$value]))),
            'date' => $this->date($value),
            'money' => number_format((float) $value, 2, '.', '') . '|' . ($currency !== '' ? $currency : 'RUB'),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    private function date(mixed $value): string
    {
        try {
            return (new \DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
