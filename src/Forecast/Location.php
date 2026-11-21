<?php

declare(strict_types=1);

namespace App\Forecast;

final readonly class Location
{
    public function __construct(
        public int $id,
        public string $name,
        public float $latitude,
        public float $longitude,
        public string $timezone,
    ) {}
}