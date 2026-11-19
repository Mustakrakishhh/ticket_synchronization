<?php

declare(strict_types=1);

namespace App\Domain;

final class ServiceType
{
    public const AIR = 'air';
    public const ACCOMMODATION = 'accommodation';
    public const RAIL = 'rail';
    public const TRANSFER = 'transfer';
    public const AEROEXPRESS = 'aeroexpress';
    public const BUS = 'bus';
    public const TAXI = 'taxi';
    public const VIP_HALL = 'vip_hall';
    public const ADDITIONAL_SERVICE = 'additional_service';
    public const OTHER = 'other';

    public static function all(): array
    {
        return [
            self::AIR => 'Авиабилеты',
            self::ACCOMMODATION => 'Проживание / отель / хостел',
            self::RAIL => 'Ж/д билеты',
            self::TRANSFER => 'Трансфер',
            self::AEROEXPRESS => 'Аэроэкспресс',
            self::BUS => 'Автобусы',
            self::TAXI => 'Такси',
            self::VIP_HALL => 'VIP-зал',
            self::ADDITIONAL_SERVICE => 'Дополнительные услуги (багаж, выбор места)',
            self::OTHER => 'Другие услуги',
        ];
    }

    public static function normalize(string|int|null $value): string
    {
        $key = strtolower(trim((string) $value));
        return match ($key) {
            '1', 'air', 'avia', 'airline' => self::AIR,
            '2', 'hotel', 'hostel', 'accommodation' => self::ACCOMMODATION,
            '3', 'transfer' => self::TRANSFER,
            '4', 'train', 'rail', 'railway' => self::RAIL,
            '5', 'aeroexpress' => self::AEROEXPRESS,
            '7', 'bus' => self::BUS,
            'taxi', 'taxivoucher' => self::TAXI,
            'vip', 'vip_hall', 'vip hall', 'viphall', 'businesslounge' => self::VIP_HALL,
            'airadditionalservice' => self::AIR,
            default => self::OTHER,
        };
    }

    public static function transportLabel(string $serviceType): ?string
    {
        return match ($serviceType) {
            self::AIR => 'Самолёт',
            self::RAIL => 'Поезд',
            self::AEROEXPRESS => 'Аэроэкспресс',
            self::BUS => 'Автобус',
            self::TAXI => 'Такси',
            self::TRANSFER => 'Трансфер',
            default => null,
        };
    }

    public static function requiresTicketNumber(string $serviceType): bool
    {
        return in_array($serviceType, [
            self::AIR,
            self::RAIL,
            self::AEROEXPRESS,
            self::BUS,
        ], true);
    }
}
