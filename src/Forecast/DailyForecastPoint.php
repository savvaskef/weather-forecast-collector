<?php

declare(strict_types=1);

namespace App\Forecast;

use DateTimeImmutable;

final readonly class DailyForecastPoint
{
    public function __construct(
        public DateTimeImmutable $forecastDateUtc,
        public ?float $tempMinC,
        public ?float $tempMaxC,
        public float $precipitationMm,
    ) {}
}