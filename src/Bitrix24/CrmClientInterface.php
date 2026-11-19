<?php

declare(strict_types=1);

namespace App\Bitrix24;

interface CrmClientInterface
{
    public function itemExists(int $entityTypeId, int $itemId): bool;

    public function findItemIdByField(int $entityTypeId, string $field, mixed $value): ?int;

    public function addItem(int $entityTypeId, array $fields): int;

    public function updateItem(int $entityTypeId, int $itemId, array $fields): void;
}
