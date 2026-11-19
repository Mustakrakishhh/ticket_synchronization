<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

final readonly class ExpenseRecord
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public array $data,
    ) {
        if ($provider === '' || $externalId === '') {
            throw new InvalidArgumentException('У затраты должны быть provider и externalId.');
        }
    }

    public function fields(): array
    {
        return [
            'expense_external_id' => $this->externalId,
            'provider' => $this->provider,
        ] + $this->data;
    }
}
