<?php

declare(strict_types=1);

namespace App\Provider;

final class AdditionalServiceFilter
{
    public static function matches(mixed $serviceType, mixed ...$labels): bool
    {
        $type = strtolower((string) preg_replace(
            '/[^a-zA-Z0-9]+/',
            '',
            trim((string) $serviceType),
        ));
        if (in_array($type, [
            'airadditionalservice',
            'additionalservice',
            'ancillary',
            'ancillaryservice',
            'baggage',
            'luggage',
            'seat',
            'seatselection',
        ], true)) {
            return true;
        }

        foreach ($labels as $label) {
            if (!is_scalar($label)) {
                continue;
            }
            $text = trim((string) $label);
            if ($text === '') {
                continue;
            }
            if (preg_match('/дополнительн(?:ая|ые|ой)\s+услуг/iu', $text) === 1
                || preg_match('/\b(?:additional|ancillary)\s+services?\b/i', $text) === 1
                || preg_match('/^\s*(?:багаж|выбор\s+места|baggage|luggage|seat\s+selection)\s*$/iu', $text) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
