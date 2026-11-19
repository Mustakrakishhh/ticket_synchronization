<?php

declare(strict_types=1);

namespace App\Sync;

use InvalidArgumentException;

final class ExpenseCatalog
{
    public const MAPPING_SCOPE = '__expenses__';
    public const IDENTITY_FIELD = 'expense_external_id';

    private const DEFINITIONS = [
        'expense_external_id' => ['label' => 'Уникальный ID затраты', 'type' => 'string', 'transform' => 'string'],
        'ticket_external_id' => ['label' => 'Уникальный ID билета / проживания', 'type' => 'string', 'transform' => 'string'],
        'provider' => ['label' => 'Источник данных', 'type' => 'string', 'transform' => 'string'],
        'supplier' => ['label' => 'Поставщик', 'type' => 'string', 'transform' => 'string'],
        'service_type' => ['label' => 'Тип затраты (код)', 'type' => 'string', 'transform' => 'string'],
        'service_type_label' => ['label' => 'Тип затраты', 'type' => 'string', 'transform' => 'string'],
        'ticket_number' => ['label' => 'Номер билета', 'type' => 'string', 'transform' => 'string'],
        'service_id' => ['label' => 'ID основной услуги', 'type' => 'string', 'transform' => 'string'],
        'service_ids' => ['label' => 'ID всех услуг', 'type' => 'string', 'transform' => 'string'],
        'order_id' => ['label' => 'ID заказа', 'type' => 'string', 'transform' => 'string'],
        'amount' => ['label' => 'Фактическая сумма затраты', 'type' => 'money', 'transform' => 'money'],
        'gross_amount' => ['label' => 'Сумма до возврата', 'type' => 'money', 'transform' => 'money'],
        'original_amount' => ['label' => 'Первоначальная сумма покупки', 'type' => 'money', 'transform' => 'money'],
        'refund_amount' => ['label' => 'Возвращённая сумма', 'type' => 'money', 'transform' => 'money'],
        'penalty_amount' => ['label' => 'Штраф при возврате', 'type' => 'money', 'transform' => 'money'],
        'non_refundable_amount' => ['label' => 'Невозвратная сумма', 'type' => 'money', 'transform' => 'money'],
        'net_amount' => ['label' => 'Итоговая фактическая затрата', 'type' => 'money', 'transform' => 'money'],
        'expense_status' => ['label' => 'Статус затраты (код)', 'type' => 'string', 'transform' => 'string'],
        'expense_status_label' => ['label' => 'Статус затраты', 'type' => 'string', 'transform' => 'string'],
        'calculation_status' => ['label' => 'Точность расчёта (код)', 'type' => 'string', 'transform' => 'string'],
        'calculation_status_label' => ['label' => 'Точность расчёта', 'type' => 'string', 'transform' => 'string'],
        'provider_status' => ['label' => 'Статус у поставщика', 'type' => 'string', 'transform' => 'string'],
        'base_amount' => ['label' => 'Стоимость билета', 'type' => 'money', 'transform' => 'money'],
        'additional_amount' => ['label' => 'Стоимость дополнительных услуг', 'type' => 'money', 'transform' => 'money'],
        'currency' => ['label' => 'Валюта', 'type' => 'string', 'transform' => 'string'],
        'passenger' => ['label' => 'Пассажир', 'type' => 'string', 'transform' => 'string'],
        'additional_services' => ['label' => 'Дополнительные услуги', 'type' => 'string', 'transform' => 'string'],
        'service_url' => ['label' => 'Ссылка на билет / заказ', 'type' => 'url', 'transform' => 'string'],
        'route' => ['label' => 'Маршрут', 'type' => 'string', 'transform' => 'string'],
        'departure_at' => ['label' => 'Дата и время отправления', 'type' => 'datetime', 'transform' => 'date'],
        'hotel_name' => ['label' => 'Название отеля / хостела', 'type' => 'string', 'transform' => 'string'],
        'hotel_address' => ['label' => 'Адрес отеля / хостела', 'type' => 'string', 'transform' => 'string'],
        'room_name' => ['label' => 'Название номера / комнаты', 'type' => 'string', 'transform' => 'string'],
        'check_in' => ['label' => 'Дата заселения', 'type' => 'datetime', 'transform' => 'date'],
        'check_out' => ['label' => 'Дата выселения', 'type' => 'datetime', 'transform' => 'date'],
        'created_at' => ['label' => 'Дата создания в сервисе', 'type' => 'datetime', 'transform' => 'date'],
        'updated_at' => ['label' => 'Дата изменения в сервисе', 'type' => 'datetime', 'transform' => 'date'],
    ];

    public static function fields(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public static function fieldDefinition(string $field): array
    {
        return self::DEFINITIONS[$field]
            ?? throw new InvalidArgumentException('Неизвестное поле затраты.');
    }
}
