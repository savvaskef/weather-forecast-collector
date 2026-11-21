<?php
declare(strict_types=1);

namespace App\Provider;

use App\Forecast\DailyForecastPoint;
use App\Forecast\HourlyForecastPoint;
use App\Forecast\Location;
use App\Forecast\NormalizedForecast;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

final class OpenMeteoClient implements WeatherProviderInterface
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(
        private readonly Client $http = new Client([
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
        ]),
    ) {}

    public function providerKey(): string
    {
        return 'open_meteo';
    }

    public function fetchForecast(Location $location, int $days = 3): NormalizedForecast
    {
        $clampedDays = max(1, min(16, $days));

        $payload = $this->requestJson([
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'timezone' => 'UTC',
            'forecast_days' => $clampedDays,
            'temperature_unit' => 'celsius',
            'precipitation_unit' => 'mm',
            'hourly' => 'temperature_2m,precipitation',
            'daily' => 'temperature_2m_min,temperature_2m_max,precipitation_sum',
        ]);

        return new NormalizedForecast(
            provider: $this->providerKey(),
            hourly: $this->parseHourly($payload),
            daily: $this->parseDaily($payload),
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function requestJson(array $query): array
    {
        $attempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http->get(self::ENDPOINT, ['query' => $query]);
                $status = $response->getStatusCode();

                if ($status === 429 || $status >= 500) {
                    $lastError = "Open-Meteo HTTP {$status}";
                    usleep($attempt * 400_000);
                    continue;
                }

                $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new ProviderException('Open-Meteo returned non-object JSON.');
                }

                return $decoded;
            } catch (GuzzleException|Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < $attempts) {
                    usleep($attempt * 400_000);
                }
            }
        }

        throw new ProviderException('Open-Meteo request failed: ' . ($lastError ?? 'unknown error'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<HourlyForecastPoint>
     */
    private function parseHourly(array $payload): array
    {
        $hourly = $payload['hourly'] ?? null;
        if (!is_array($hourly)) {
            throw new ProviderException('Open-Meteo response missing hourly block.');
        }

        $times = $hourly['time'] ?? null;
        $temps = $hourly['temperature_2m'] ?? null;
        $precips = $hourly['precipitation'] ?? null;

        if (!is_array($times) || !is_array($temps) || !is_array($precips)) {
            throw new ProviderException('Open-Meteo hourly arrays malformed.');
        }

        $utc = new DateTimeZone('UTC');
        $points = [];
        $count = min(count($times), count($temps), count($precips));

        for ($i = 0; $i < $count; $i++) {
            if ($temps[$i] === null) {
                continue;
            }
            $dt = new DateTimeImmutable((string) $times[$i], $utc);
            $points[] = new HourlyForecastPoint(
                validAtUtc: $dt->setTimezone($utc),
                temperatureC: (float) $temps[$i],
                precipitationMm: max(0.0, (float) ($precips[$i] ?? 0.0)),
            );
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<DailyForecastPoint>
     */
    private function parseDaily(array $payload): array
    {
        $daily = $payload['daily'] ?? null;
        if (!is_array($daily)) {
            throw new ProviderException('Open-Meteo response missing daily block.');
        }

        $dates = $daily['time'] ?? null;
        $mins = $daily['temperature_2m_min'] ?? null;
        $maxs = $daily['temperature_2m_max'] ?? null;
        $precips = $daily['precipitation_sum'] ?? null;

        if (!is_array($dates) || !is_array($mins) || !is_array($maxs) || !is_array($precips)) {
            throw new ProviderException('Open-Meteo daily arrays malformed.');
        }

        $utc = new DateTimeZone('UTC');
        $points = [];
        $count = min(count($dates), count($mins), count($maxs), count($precips));

        for ($i = 0; $i < $count; $i++) {
            $points[] = new DailyForecastPoint(
                forecastDateUtc: new DateTimeImmutable((string) $dates[$i] . ' 00:00:00', $utc),
                tempMinC: $mins[$i] !== null ? (float) $mins[$i] : null,
                tempMaxC: $maxs[$i] !== null ? (float) $maxs[$i] : null,
                precipitationMm: max(0.0, (float) ($precips[$i] ?? 0.0)),
            );
        }

        return $points;
    }
}