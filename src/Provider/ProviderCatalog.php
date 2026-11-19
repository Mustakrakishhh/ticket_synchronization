<?php

declare(strict_types=1);

namespace App\Provider;

use InvalidArgumentException;

final class ProviderCatalog
{
    private const NORMALIZED_FIELDS = [
        'external_id', 'provider', 'service_type', 'service_type_label', 'transport_type',
        'order_id', 'service_id', 'ticket_number', 'booking_number', 'service_url',
        'status', 'status_code', 'passenger', 'passenger_first_name',
        'passenger_last_name', 'passenger_middle_name', 'route',
        'departure_location', 'arrival_location',
        'departure_at', 'arrival_at', 'check_in', 'check_out', 'amount', 'currency',
        'ticket_amount', 'service_fee', 'additional_amount', 'additional_service_types',
        'company_name', 'company_inn', 'hotel_name', 'hotel_address',
        'room_name', 'created_at', 'updated_at', 'project', 'raw_json',
    ];

    private const FIELD_DEFINITIONS = [
        'external_id' => ['label' => 'Уникальный ID записи', 'type' => 'string', 'transform' => 'string'],
        'provider' => ['label' => 'Источник данных', 'type' => 'string', 'transform' => 'string'],
        'service_type' => ['label' => 'Тип услуги (код)', 'type' => 'string', 'transform' => 'string'],
        'service_type_label' => ['label' => 'Тип услуги', 'type' => 'string', 'transform' => 'string'],
        'transport_type' => ['label' => 'Вид транспорта', 'type' => 'string', 'transform' => 'string'],
        'order_id' => ['label' => 'ID заказа', 'type' => 'string', 'transform' => 'string'],
        'short_order_id' => ['label' => 'Короткий ID заказа Aviasales', 'type' => 'string', 'transform' => 'string'],
        'service_id' => ['label' => 'ID услуги', 'type' => 'string', 'transform' => 'string'],
        'ticket_number' => ['label' => 'Номер билета', 'type' => 'string', 'transform' => 'string'],
        'booking_number' => ['label' => 'Номер бронирования', 'type' => 'string', 'transform' => 'string'],
        'service_url' => ['label' => 'Ссылка на билет / заказ в сервисе', 'type' => 'url', 'transform' => 'string'],
        'status' => ['label' => 'Статус', 'type' => 'string', 'transform' => 'string'],
        'status_code' => ['label' => 'Код статуса', 'type' => 'string', 'transform' => 'string'],
        'passenger' => ['label' => 'Пассажир', 'type' => 'string', 'transform' => 'string'],
        'passenger_first_name' => ['label' => 'Имя пассажира', 'type' => 'string', 'transform' => 'string'],
        'passenger_last_name' => ['label' => 'Фамилия пассажира', 'type' => 'string', 'transform' => 'string'],
        'passenger_middle_name' => ['label' => 'Отчество пассажира', 'type' => 'string', 'transform' => 'string'],
        'route' => ['label' => 'Маршрут', 'type' => 'string', 'transform' => 'string'],
        'departure_location' => ['label' => 'Откуда / место отправления', 'type' => 'string', 'transform' => 'string'],
        'arrival_location' => ['label' => 'Куда / место прибытия', 'type' => 'string', 'transform' => 'string'],
        'departure_at' => ['label' => 'Дата и время отправления (билет)', 'type' => 'datetime', 'transform' => 'date'],
        'arrival_at' => ['label' => 'Дата и время прибытия (билет)', 'type' => 'datetime', 'transform' => 'date'],
        'check_in' => ['label' => 'Дата заселения (проживание)', 'type' => 'datetime', 'transform' => 'date'],
        'check_out' => ['label' => 'Дата выселения (проживание)', 'type' => 'datetime', 'transform' => 'date'],
        'amount' => ['label' => 'Стоимость', 'type' => 'money', 'transform' => 'money'],
        'ticket_amount' => ['label' => 'Стоимость билета без комиссии и допуслуг', 'type' => 'money', 'transform' => 'money'],
        'service_fee' => ['label' => 'Комиссия сервиса', 'type' => 'money', 'transform' => 'money'],
        'additional_amount' => ['label' => 'Стоимость допуслуг (со сборами)', 'type' => 'money', 'transform' => 'money'],
        'additional_service_types' => ['label' => 'Виды доп. услуг', 'type' => 'string', 'multiple' => true, 'transform' => 'string_multiple'],
        'additional_service_files' => ['label' => 'Документы дополнительных услуг', 'type' => 'file', 'multiple' => true, 'transform' => 'file_multiple'],
        'currency' => ['label' => 'Валюта', 'type' => 'string', 'transform' => 'string'],
        'company_name' => ['label' => 'Название компании', 'type' => 'string', 'transform' => 'string'],
        'company_inn' => ['label' => 'ИНН компании', 'type' => 'string', 'transform' => 'string'],
        'hotel_name' => ['label' => 'Название отеля / хостела', 'type' => 'string', 'transform' => 'string'],
        'hotel_address' => ['label' => 'Адрес отеля / хостела', 'type' => 'string', 'transform' => 'string'],
        'room_name' => ['label' => 'Название номера / комнаты', 'type' => 'string', 'transform' => 'string'],
        'created_at' => ['label' => 'Дата создания в сервисе', 'type' => 'datetime', 'transform' => 'date'],
        'updated_at' => ['label' => 'Дата изменения в сервисе', 'type' => 'datetime', 'transform' => 'date'],
        'project' => ['label' => 'Проект / центр затрат', 'type' => 'string', 'transform' => 'string'],
        'raw_json' => ['label' => 'Исходный ответ API (JSON)', 'type' => 'string', 'transform' => 'string'],
        'ticket_file' => ['label' => 'Билет / маршрутная квитанция', 'type' => 'file', 'transform' => 'file'],
        'buyer' => ['label' => 'Покупатель', 'type' => 'string', 'transform' => 'string'],
    ];

    private const PROVIDERS = [
        'trivio' => [
            'title' => 'Trivio',
            'description' => 'Токен Integration API получает новые операции, а логин и пароль Client API выполняют полную сверку всех услуг за выбранный период.',
            'identityField' => 'external_id',
            'credentials' => [
                ['key' => 'token', 'label' => 'Токен Integration API', 'type' => 'password', 'required' => true],
                ['key' => 'login', 'label' => 'Логин Trivio Client API', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Пароль Trivio Client API', 'type' => 'password', 'required' => true],
            ],
            'fields' => [...self::NORMALIZED_FIELDS, 'ticket_file', 'additional_service_files'],
        ],
        'aviasales' => [
            'title' => 'Авиасейлс для бизнеса',
            'description' => 'B2B API заказов: API-ключ передаётся в заголовке Token, UUID компании — параметром current_company.',
            'identityField' => 'external_id',
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API-ключ', 'type' => 'password', 'required' => true],
                ['key' => 'company_id', 'label' => 'UUID компании', 'type' => 'text', 'required' => true],
            ],
            'fields' => [...self::NORMALIZED_FIELDS, 'short_order_id', 'buyer', 'ticket_file'],
        ],
        'smartway' => [
            'title' => 'Smartway',
            'description' => 'Официальный JSON-RPC API v2: поездки, приобретённые услуги и PDF-ваучеры.',
            'identityField' => 'external_id',
            'credentials' => [
                ['key' => 'username', 'label' => 'Логин Smartway (email)', 'type' => 'text', 'required' => true],
                ['key' => 'password', 'label' => 'Пароль Smartway', 'type' => 'password', 'required' => true],
            ],
            'fields' => [...self::NORMALIZED_FIELDS, 'ticket_file'],
        ],
    ];

    public static function all(): array
    {
        return array_map(
            static fn (array $provider, string $code): array => ['code' => $code] + self::decorate($provider),
            self::PROVIDERS,
            array_keys(self::PROVIDERS),
        );
    }

    public static function get(string $code): array
    {
        $provider = self::PROVIDERS[$code]
            ?? throw new InvalidArgumentException('Неизвестный провайдер.');

        return self::decorate($provider);
    }

    public static function fieldDefinition(string $sourceField): array
    {
        return self::FIELD_DEFINITIONS[$sourceField]
            ?? throw new InvalidArgumentException('Неизвестное поле источника.');
    }

    public static function validateCredentials(string $code, array $credentials): array
    {
        $definition = self::get($code);
        $clean = [];
        foreach ($definition['credentials'] as $field) {
            $value = trim((string) ($credentials[$field['key']] ?? $field['default'] ?? ''));
            if (!empty($field['required']) && $value === '') {
                throw new InvalidArgumentException("Заполните поле «{$field['label']}».");
            }
            if (isset($field['options'])) {
                $allowed = array_column($field['options'], 'value');
                if (!in_array($value, $allowed, true)) {
                    throw new InvalidArgumentException("Выберите допустимое значение поля «{$field['label']}».");
                }
            }
            $clean[$field['key']] = $value;
        }
        return $clean;
    }

    private static function decorate(array $provider): array
    {
        $provider['fieldDefinitions'] = array_intersect_key(
            self::FIELD_DEFINITIONS,
            array_flip($provider['fields']),
        );

        return $provider;
    }
}
