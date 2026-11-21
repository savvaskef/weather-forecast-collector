<?php

declare(strict_types=1);

namespace App\Forecast;

final readonly class NormalizedForecast
{
    /**
     * @param list<HourlyForecastPoint> $hourly
     * @param list<DailyForecastPoint> $daily
     */
    public function __construct(
        public string $provider,
        public array $hourly,
        public array $daily,
    ) {}
}