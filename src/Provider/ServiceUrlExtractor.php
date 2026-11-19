<?php

declare(strict_types=1);

namespace App\Provider;

final class ServiceUrlExtractor
{
    private const URL_KEYS = [
        'ticket_url', 'ticketUrl',
        'order_url', 'orderUrl',
        'service_url', 'serviceUrl',
        'web_url', 'webUrl',
        'deep_link', 'deepLink', 'deeplink',
        'href', 'url',
    ];

    private const SKIPPED_CONTAINERS = [
        'itineraries', 'files', 'documents', 'attachments',
        'download', 'downloads',
    ];

    public static function from(mixed ...$sources): ?string
    {
        foreach ($sources as $source) {
            $url = self::find($source, 0);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public static function forProvider(string $provider, mixed $orderId, mixed ...$sources): ?string
    {
        $orderId = trim((string) $orderId);
        if ($orderId === '') {
            return self::from(...$sources);
        }

        $generatedUrl = match (strtolower(trim($provider))) {
            'aviasales' => preg_match('/^[a-zA-Z0-9-]{8,}$/', $orderId) === 1
                ? 'https://b2b.aviasales.ru/panel/orders/' . rawurlencode($orderId)
                : null,
            'smartway' => ctype_digit($orderId) && (int) $orderId > 0
                ? 'https://po.smartway.today/trip/' . rawurlencode($orderId)
                : null,
            'trivio' => ctype_digit($orderId) && (int) $orderId > 0
                ? 'https://corporate.trivio.ru/order/show?order_id=' . rawurlencode($orderId) . '#data'
                : null,
            default => null,
        };

        return $generatedUrl ?? self::from(...$sources);
    }

    private static function find(mixed $value, int $depth): ?string
    {
        if (!is_array($value) || $depth > 5) {
            return null;
        }

        foreach (self::URL_KEYS as $key) {
            $candidate = $value[$key] ?? null;
            if (is_string($candidate) && self::isSafeUrl($candidate)) {
                return trim($candidate);
            }
        }

        foreach ($value as $key => $child) {
            if (in_array((string) $key, self::SKIPPED_CONTAINERS, true)) {
                continue;
            }
            $url = self::find($child, $depth + 1);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private static function isSafeUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
