<?php

declare(strict_types=1);

namespace App\Provider;

interface TravelProviderInterface
{
    public function code(): string;

    public function testConnection(): void;

    /**
     * @return array{
     *   items: iterable<\App\Domain\TravelItem>,
     *   nextCursor: ?string,
     *   sourceMode?: string,
     *   queueTransactions?: int,
     *   historyOrders?: int,
     *   historyOrderErrors?: int,
     *   sourceErrors?: list<string>
     * }
     */
    public function fetch(?string $cursor, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
