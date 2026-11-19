<?php

declare(strict_types=1);

namespace App\Bitrix24;

interface NotificationClientInterface
{
    /** @return list<array{id: int, name: string, position: string}> */
    public function users(): array;

    public function sendSystemNotification(int $userId, string $message, string $tag): void;
}
