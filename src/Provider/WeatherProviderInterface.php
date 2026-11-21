<?php

declare(strict_types=1);

namespace App\Provider;

use App\Forecast\Location;
use App\Forecast\NormalizedForecast;

interface WeatherProviderInterface
{
    public function providerKey(): string;

    /**
     * @throws ProviderException
     */
    public function fetchForecast(Location $location, int $days = 3): NormalizedForecast;
}