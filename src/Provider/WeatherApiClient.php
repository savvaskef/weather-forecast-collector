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

final class WeatherApiClient implements WeatherProviderInterface
{
    private const ENDPOINT = 'https://api.weatherapi.com/v1/forecast.json';

    public function __construct(
        private readonly string $apiKey,
        private readonly Client $http = new Client([
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
        ]),
    ) {}

    public function providerKey(): string
    {
        return 'weatherapi';
    }

    public function fetchForecast(Location $location, int $days = 3): NormalizedForecast
    {
        if (trim($this->apiKey) === '') {
            throw new ProviderException('WeatherAPI key is empty.');
        }

        // Free tier supports up to 3 forecast days
        $clampedDays = max(1, min(3, $days));

        $payload = $this->requestJson([
            'key' => $this->apiKey,
            'q' => sprintf('%.5f,%.5f', $location->latitude, $location->longitude),
            'days' => $clampedDays,
            'aqi' => 'no',
            'alerts' => 'no',
        ]);

        if (isset($payload['error']) && is_array($payload['error'])) {
            $msg = (string) ($payload['error']['message'] ?? 'Unknown WeatherAPI error');
            $code = (string) ($payload['error']['code'] ?? '');
            throw new ProviderException("WeatherAPI error ({$code}): {$msg}");
        }

        $forecastDays = $payload['forecast']['forecastday'] ?? null;
        if (!is_array($forecastDays)) {
            throw new ProviderException('WeatherAPI response missing forecast.forecastday.');
        }

        $utc = new DateTimeZone('UTC');
        $fallbackTz = $this->safeTimeZone($location->timezone, $utc);
        $hourly = [];
        $daily = [];

        foreach ($forecastDays as $dayEntry) {
            if (!is_array($dayEntry)) {
                continue;
            }

            $dayStats = $dayEntry['day'] ?? [];
            $dateStr = (string) ($dayEntry['date'] ?? '');
            if ($dateStr !== '' && is_array($dayStats)) {
                $daily[] = new DailyForecastPoint(
                    forecastDateUtc: new DateTimeImmutable($dateStr . ' 00:00:00', $utc),
                    tempMinC: isset($dayStats['mintemp_c']) ? (float) $dayStats['mintemp_c'] : null,
                    tempMaxC: isset($dayStats['maxtemp_c']) ? (float) $dayStats['maxtemp_c'] : null,
                    precipitationMm: max(0.0, (float) ($dayStats['totalprecip_mm'] ?? 0.0)),
                );
            }

            foreach (($dayEntry['hour'] ?? []) as $hourEntry) {
                if (!is_array($hourEntry) || !isset($hourEntry['temp_c'])) {
                    continue;
                }

                if (isset($hourEntry['time_epoch']) && is_numeric($hourEntry['time_epoch'])) {
                    $dtUtc = (new DateTimeImmutable('@' . (int) $hourEntry['time_epoch']))->setTimezone($utc);
                } elseif (!empty($hourEntry['time'])) {
                    $dtUtc = (new DateTimeImmutable((string) $hourEntry['time'], $fallbackTz))->setTimezone($utc);
                } else {
                    continue;
                }

                $hourly[] = new HourlyForecastPoint(
                    validAtUtc: $dtUtc,
                    temperatureC: (float) $hourEntry['temp_c'],
                    precipitationMm: max(0.0, (float) ($hourEntry['precip_mm'] ?? 0.0)),
                );
            }
        }

        return new NormalizedForecast(
            provider: $this->providerKey(),
            hourly: $hourly,
            daily: $daily,
        );
    }

    private function safeTimeZone(string $tz, DateTimeZone $fallback): DateTimeZone
    {
        try {
            return new DateTimeZone($tz);
        } catch (Throwable) {
            return $fallback;
        }
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
                    $lastError = "WeatherAPI HTTP {$status}";
                    usleep($attempt * 400_000);
                    continue;
                }

                $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new ProviderException('WeatherAPI returned non-object JSON.');
                }

                return $decoded;
            } catch (GuzzleException|Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < $attempts) {
                    usleep($attempt * 400_000);
                }
            }
        }

        throw new ProviderException('WeatherAPI request failed: ' . ($lastError ?? 'unknown error'));
    }
}