<?php

declare(strict_types=1);

namespace App\Bitrix24;

use Bitrix24\SDK\Services\ServiceBuilder;
use RuntimeException;
use Throwable;

final readonly class Bitrix24Client implements CrmClientInterface, NotificationClientInterface
{
    public function __construct(private ServiceBuilder $service)
    {
    }

    public function call(string $method, array $parameters = [], bool $retry = true): array
    {
        $lastError = null;
        $attempts = $retry ? 3 : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->service->core
                    ->call($method, $parameters)
                    ->getResponseData()
                    ->getResult();
            } catch (Throwable $exception) {
                $lastError = $exception;
                if ($attempt >= $attempts || !$this->isTransient($exception)) {
                    break;
                }
                usleep(200_000 * $attempt + random_int(0, 150_000));
            }
        }

        $message = $lastError?->getMessage() ?? 'неизвестная ошибка';
        $upperMessage = strtoupper($message);
        if (str_contains($upperMessage, 'METHOD NOT FOUND') || str_contains($upperMessage, 'METHOD_NOT_FOUND')) {
            $message .= ' Метод отсутствует в доступных методах текущего OAuth-токена. '
                . 'Проверьте права локального приложения Bitrix24 и повторно откройте приложение для обновления токена.';
        }
        throw new RuntimeException(
            sprintf('Ошибка Bitrix24 REST (%s): %s', $method, $message),
            previous: $lastError,
        );
    }

    public function smartProcesses(): array
    {
        $result = $this->call('crm.type.list', [
            'order' => ['title' => 'ASC'],
            'select' => ['id', 'title', 'entityTypeId'],
        ], false);
        return array_values(array_map(
            static fn (array $type): array => [
                'id' => (int) ($type['id'] ?? 0),
                'entityTypeId' => (int) ($type['entityTypeId'] ?? 0),
                'title' => (string) ($type['title'] ?? ''),
            ],
            $result['types'] ?? $result,
        ));
    }

    public function currentUser(): array
    {
        $user = $this->call('user.current');
        $id = (int) ($user['ID'] ?? $user['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Bitrix24 не вернул ID текущего пользователя.');
        }
        try {
            $admin = $this->service
                ->getMainScope()
                ->main()
                ->isCurrentUserHasAdminRights()
                ->isAdmin();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Не удалось проверить права администратора Bitrix24 через user.admin: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
        return [
            'id' => $id,
            'admin' => $admin,
            'name' => trim(implode(' ', array_filter([
                (string) ($user['NAME'] ?? $user['name'] ?? ''),
                (string) ($user['LAST_NAME'] ?? $user['lastName'] ?? ''),
            ]))),
        ];
    }

    /** @return array{isExisting: bool, isAvailable: bool} */
    public function methodAvailability(string $method): array
    {
        $method = strtolower(trim($method));
        if ($method === '') {
            throw new RuntimeException('Не указано имя метода Bitrix24 для проверки.');
        }

        $result = $this->call('method.get', ['name' => $method]);
        return [
            'isExisting' => $this->bitrixBoolean($result['isExisting'] ?? false),
            'isAvailable' => $this->bitrixBoolean($result['isAvailable'] ?? false),
        ];
    }

    /** @param list<string> $methods @return array<string, array{isExisting: bool, isAvailable: bool}> */
    public function capabilities(array $methods): array
    {
        $capabilities = [];
        foreach (array_values(array_unique($methods)) as $method) {
            $method = strtolower(trim($method));
            if ($method !== '') {
                $capabilities[$method] = $this->methodAvailability($method);
            }
        }
        return $capabilities;
    }

    public function fields(int $entityTypeId): array
    {
        if ($entityTypeId <= 0) {
            throw new RuntimeException('Не выбран смарт-процесс.');
        }
        // This response can be large on portals with many custom fields. Do not
        // multiply one slow request by the generic three-attempt retry policy.
        $result = $this->call('crm.item.fields', [
            'entityTypeId' => $entityTypeId,
            'useOriginalUfNames' => 'N',
        ], false);
        $fields = $result['fields'] ?? $result;
        $output = [];
        foreach ($fields as $code => $field) {
            if (!is_array($field) || $this->bitrixBoolean($field['isReadOnly'] ?? false)) {
                continue;
            }
            $output[] = [
                'code' => (string) $code,
                'upperName' => (string) ($field['upperName'] ?? ''),
                'title' => (string) ($field['title'] ?? $field['formLabel'] ?? $code),
                'type' => strtolower((string) ($field['type'] ?? 'string')),
                'required' => $this->bitrixBoolean($field['isRequired'] ?? false),
                'multiple' => $this->bitrixBoolean($field['isMultiple'] ?? false),
            ];
        }
        usort($output, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        return $output;
    }

    private function bitrixBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        return in_array(strtoupper(trim((string) $value)), ['Y', 'YES', 'TRUE', '1'], true);
    }

    public function ensureField(int $entityTypeId, string $sourceField, array $definition): array
    {
        if ($entityTypeId <= 0) {
            throw new RuntimeException('Не выбран смарт-процесс.');
        }

        $entityId = "CRM_{$entityTypeId}";
        $fieldName = $this->travelSyncFieldName($entityTypeId, $sourceField);
        $existing = $this->call('userfieldconfig.list', [
            'moduleId' => 'crm',
            'select' => ['*'],
            'filter' => ['fieldName' => $fieldName],
        ]);
        $fields = is_array($existing['fields'] ?? null) ? $existing['fields'] : [];
        foreach ($fields as $field) {
            if (is_array($field)
                && ($field['entityId'] ?? null) === $entityId
                && ($field['fieldName'] ?? null) === $fieldName
            ) {
                $expectedType = (string) ($definition['type'] ?? 'string');
                if (($field['userTypeId'] ?? $expectedType) !== $expectedType) {
                    throw new RuntimeException(
                        "Поле {$fieldName} уже существует, но имеет несовместимый тип данных."
                    );
                }
                if (!empty($definition['multiple']) && !$this->bitrixBoolean($field['multiple'] ?? false)) {
                    throw new RuntimeException("Поле {$fieldName} должно быть множественным. Измените настройку поля в Bitrix24.");
                }
                return ['created' => false, 'config' => $field];
            }
        }

        $settings = $sourceField === 'raw_json' ? ['ROWS' => 8] : [];
        $result = $this->call('userfieldconfig.add', [
            'moduleId' => 'crm',
            'field' => [
                'entityId' => $entityId,
                'fieldName' => $fieldName,
                'userTypeId' => (string) ($definition['type'] ?? 'string'),
                'xmlId' => 'TRAVEL_SYNC_' . strtoupper($sourceField),
                'sort' => 500,
                'multiple' => !empty($definition['multiple']) ? 'Y' : 'N',
                'mandatory' => 'N',
                'showFilter' => 'N',
                'editInList' => 'Y',
                'isSearchable' => in_array($sourceField, ['external_id', 'expense_external_id'], true) ? 'Y' : 'N',
                'settings' => $settings,
                'editFormLabel' => [
                    'ru' => (string) ($definition['label'] ?? $sourceField),
                    'en' => (string) ($definition['label'] ?? $sourceField),
                ],
                'helpMessage' => [
                    'ru' => 'Поле создано приложением Travel Sync.',
                    'en' => 'Created by Travel Sync.',
                ],
            ],
        ], false);

        $field = is_array($result['field'] ?? null) ? $result['field'] : [];
        if (($field['fieldName'] ?? null) !== $fieldName) {
            throw new RuntimeException('Bitrix24 не вернул созданное пользовательское поле.');
        }

        return ['created' => true, 'config' => $field];
    }

    private function travelSyncFieldName(int $entityTypeId, string $sourceField): string
    {
        $prefix = "UF_CRM_{$entityTypeId}_";
        $suffix = 'TRAVEL_SYNC_' . strtoupper((string) preg_replace('/[^A-Z0-9_]/i', '_', $sourceField));
        $maximum = 50 - strlen($prefix);
        if (strlen($suffix) > $maximum) {
            $hash = substr(hash('sha256', $suffix), 0, 8);
            $suffix = substr($suffix, 0, max(1, $maximum - 9)) . '_' . $hash;
        }

        return $prefix . $suffix;
    }

    public function categories(int $entityTypeId): array
    {
        if ($entityTypeId <= 0) {
            throw new RuntimeException('Не выбран смарт-процесс.');
        }
        // Settings metadata is requested interactively. A single bounded attempt keeps
        // the iframe responsive; the metadata cache can return the last good snapshot.
        $result = $this->call('crm.category.list', ['entityTypeId' => $entityTypeId], false);
        $categories = $result['categories'] ?? $result;
        return array_values(array_map(
            static fn (array $category): array => [
                'id' => (int) ($category['id'] ?? 0),
                'name' => (string) ($category['name'] ?? ''),
                'isDefault' => ($category['isDefault'] ?? 'N') === 'Y',
            ],
            is_array($categories) ? $categories : [],
        ));
    }

    public function stages(int $entityTypeId, int $categoryId): array
    {
        if ($entityTypeId <= 0 || $categoryId < 0) {
            throw new RuntimeException('Не выбрана воронка смарт-процесса.');
        }
        $result = $this->call('crm.status.list', [
            'filter' => ['ENTITY_ID' => "DYNAMIC_{$entityTypeId}_STAGE_{$categoryId}"],
            'order' => ['SORT' => 'ASC'],
        ], false);
        return array_values(array_map(
            static fn (array $stage): array => [
                'id' => (string) ($stage['STATUS_ID'] ?? ''),
                'name' => (string) ($stage['NAME'] ?? ''),
                'sort' => (int) ($stage['SORT'] ?? 0),
            ],
            is_array($result) ? $result : [],
        ));
    }

    public function addItem(int $entityTypeId, array $fields): int
    {
        $parameters = [
            'entityTypeId' => $entityTypeId,
            'fields' => $fields,
        ];
        $result = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                // Do not use the generic retry policy for a create request: a timeout can
                // mean that Bitrix24 created the item but lost the response. METHOD_NOT_FOUND
                // is safe to retry because the requested method was not executed.
                $result = $this->call('crm.item.add', $parameters, false);
                break;
            } catch (Throwable $exception) {
                if (!$this->isMethodNotFound($exception) || $attempt >= 3) {
                    throw $exception;
                }
                $availability = $this->methodAvailability('crm.item.add');
                if (!$availability['isExisting'] || !$availability['isAvailable']) {
                    throw new RuntimeException(
                        'Метод crm.item.add недоступен текущему OAuth-токену. '
                        . 'Проверьте право crm в настройках локального приложения Bitrix24 '
                        . 'и повторно откройте приложение от имени администратора.',
                        previous: $exception,
                    );
                }
                usleep(250_000 * $attempt + random_int(0, 100_000));
            }
        }
        if (!is_array($result)) {
            throw new RuntimeException('Bitrix24 не вернул результат crm.item.add.');
        }
        $id = (int) ($result['item']['id'] ?? $result['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Bitrix24 не вернул ID созданной карточки.');
        }
        return $id;
    }

    public function itemExists(int $entityTypeId, int $itemId): bool
    {
        if ($entityTypeId <= 0 || $itemId <= 0) {
            return false;
        }
        $result = $this->call('crm.item.list', [
            'entityTypeId' => $entityTypeId,
            'filter' => ['id' => $itemId],
            'select' => ['id'],
        ]);
        $items = is_array($result['items'] ?? null) ? $result['items'] : $result;
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === $itemId) {
                return true;
            }
        }

        return false;
    }

    public function findItemIdByField(int $entityTypeId, string $field, mixed $value): ?int
    {
        $field = trim($field);
        if ($entityTypeId <= 0 || $field === '' || $value === null || $value === '') {
            return null;
        }
        $result = $this->call('crm.item.list', [
            'entityTypeId' => $entityTypeId,
            'filter' => [$field => $value],
            'select' => ['id', $field],
            'order' => ['id' => 'DESC'],
            'start' => 0,
        ]);
        $items = is_array($result['items'] ?? null) ? $result['items'] : $result;
        foreach (is_array($items) ? $items : [] as $item) {
            $id = is_array($item) ? (int) ($item['id'] ?? 0) : 0;
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    public function updateItem(int $entityTypeId, int $itemId, array $fields): void
    {
        if ($itemId <= 0) {
            throw new RuntimeException('Не указан ID карточки Bitrix24.');
        }
        $this->call('crm.item.update', [
            'entityTypeId' => $entityTypeId,
            'id' => $itemId,
            'fields' => $fields,
        ]);
    }

    public function users(): array
    {
        $users = [];
        for ($start = 0; $start < 5000; $start += 50) {
            $result = $this->call('user.get', [
                'FILTER' => ['ACTIVE' => 'Y'],
                'sort' => 'ID',
                'order' => 'ASC',
                'start' => $start,
            ], false);
            $rows = is_array($result['users'] ?? null) ? $result['users'] : $result;
            $rows = array_values(array_filter($rows, 'is_array'));
            foreach ($rows as $user) {
                $id = (int) ($user['ID'] ?? $user['id'] ?? 0);
                if ($id <= 0 || ($user['ACTIVE'] ?? $user['active'] ?? true) === false) {
                    continue;
                }
                $name = trim(implode(' ', array_filter([
                    (string) ($user['NAME'] ?? $user['name'] ?? ''),
                    (string) ($user['LAST_NAME'] ?? $user['lastName'] ?? ''),
                ])));
                $users[$id] = [
                    'id' => $id,
                    'name' => $name !== '' ? $name : "Пользователь {$id}",
                    'position' => trim((string) ($user['WORK_POSITION'] ?? $user['workPosition'] ?? '')),
                ];
            }
            if (count($rows) < 50) {
                break;
            }
        }
        uasort($users, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        return array_values($users);
    }

    public function sendSystemNotification(int $userId, string $message, string $tag): void
    {
        if ($userId <= 0 || trim($message) === '') {
            throw new RuntimeException('Не указан получатель или текст уведомления Bitrix24.');
        }
        try {
            $this->call('im.notify.system.add', [
                'USER_ID' => $userId,
                'MESSAGE' => $message,
                'MESSAGE_OUT' => preg_replace('/\[\/?B\]/i', '', $message) ?? $message,
                'TAG' => substr($tag, 0, 255),
            ]);
        } catch (Throwable $exception) {
            if ($this->isMethodNotFound($exception)) {
                throw new RuntimeException(
                    'Метод im.notify.system.add недоступен текущему OAuth-токену. '
                    . 'Добавьте право im в настройках приложения Bitrix24, укажите '
                    . 'BITRIX24_SCOPE=crm,im,user_basic,userfieldconfig и повторно откройте приложение от имени администратора.',
                    previous: $exception,
                );
            }
            throw $exception;
        }
    }

    private function isTransient(Throwable $exception): bool
    {
        $message = strtoupper($exception->getMessage());
        foreach ([
            'QUERY_LIMIT_EXCEEDED',
            'TOO MANY REQUESTS',
            'HTTP 429',
            'HTTP 500',
            'HTTP 502',
            'HTTP 503',
            'HTTP 504',
            'TIMED OUT',
            'TIMEOUT',
            'CONNECTION RESET',
            'TEMPORARILY UNAVAILABLE',
            'API METHOD NOT FOUND',
            'METHOD_NOT_FOUND',
            'METHOD NOT FOUND',
        ] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function isMethodNotFound(Throwable $exception): bool
    {
        $message = strtoupper($exception->getMessage());
        return str_contains($message, 'API METHOD NOT FOUND')
            || str_contains($message, 'METHOD_NOT_FOUND')
            || str_contains($message, 'METHOD NOT FOUND');
    }
}
