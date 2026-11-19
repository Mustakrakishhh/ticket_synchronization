<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

final readonly class TravelItem
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $serviceType,
        public array $data,
    ) {
        if ($provider === '' || $externalId === '') {
            throw new InvalidArgumentException('У нормализованной услуги должны быть provider и externalId.');
        }
    }

    public function fields(): array
    {
        return [
            'provider' => $this->provider,
            'external_id' => $this->externalId,
            'service_type' => $this->serviceType,
            'service_type_label' => ServiceType::all()[$this->serviceType] ?? ServiceType::all()[ServiceType::OTHER],
            'transport_type' => ServiceType::transportLabel($this->serviceType),
        ] + $this->data;
    }

    public function checksum(): string
    {
        return hash('sha256', json_encode(
            $this->fields(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }
}
