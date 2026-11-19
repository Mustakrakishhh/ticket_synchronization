<?php

declare(strict_types=1);

namespace App\Provider;

interface ProviderFactoryInterface
{
    public function create(string $provider, array $credentials): TravelProviderInterface;
}
