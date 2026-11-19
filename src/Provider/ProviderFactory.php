<?php

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Aviasales\AviasalesProvider;
use App\Provider\Smartway\SmartwayProvider;
use App\Provider\Trivio\TrivioProvider;
use InvalidArgumentException;

final class ProviderFactory implements ProviderFactoryInterface
{
    public function create(string $provider, array $credentials): TravelProviderInterface
    {
        return match ($provider) {
            'trivio' => new TrivioProvider($credentials),
            'aviasales' => new AviasalesProvider($credentials),
            'smartway' => new SmartwayProvider($credentials),
            default => throw new InvalidArgumentException("Провайдер {$provider} не поддерживается."),
        };
    }
}
