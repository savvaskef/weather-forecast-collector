<?php

declare(strict_types=1);

namespace App\Forecast;

use DateTimeImmutable;

final readonly class HourlyForecastPoint
{
    public function __construct(
        public DateTimeImmutable $validAtUtc,
        public float $temperatureC,
        public float $precipitationMm,
    ) {}
}