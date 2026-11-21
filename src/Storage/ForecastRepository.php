<?php
declare(strict_types=1);

namespace App\Storage;

use App\Forecast\Location;
use App\Forecast\NormalizedForecast;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class ForecastRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function ensureLocation(string $name, float $latitude, float $longitude, string $timezone): Location
    {
        $sql = <<<'SQL'
INSERT INTO locations (name, latitude, longitude, timezone)
VALUES (:name, :latitude, :longitude, :timezone)
ON CONFLICT (latitude, longitude) DO UPDATE
SET name = EXCLUDED.name,
    timezone = EXCLUDED.timezone
RETURNING id, name, latitude, longitude, timezone
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'latitude' => round($latitude, 5),
            'longitude' => round($longitude, 5),
            'timezone' => $timezone,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('Failed to upsert location.');
        }

        return new Location(
            id: (int) $row['id'],
            name: (string) $row['name'],
            latitude: (float) $row['latitude'],
            longitude: (float) $row['longitude'],
            timezone: (string) $row['timezone'],
        );
    }

    /**
     * @return list<Location>
     */
    public function allLocations(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, latitude, longitude, timezone FROM locations ORDER BY id ASC'
        );

        $locations = [];
        foreach ($stmt->fetchAll() as $row) {
            $locations[] = new Location(
                id: (int) $row['id'],
                name: (string) $row['name'],
                latitude: (float) $row['latitude'],
                longitude: (float) $row['longitude'],
                timezone: (string) $row['timezone'],
            );
        }

        return $locations;
    }

    public function hasSuccessfulRunToday(int $locationId, string $provider, DateTimeImmutable $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'))): bool
    {
        $dayStart = $utcNow->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 00:00:00+00');
        $dayEnd = $utcNow->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d 23:59:59+00');

        $sql = <<<'SQL'
SELECT 1
FROM forecast_runs
WHERE location_id = :location_id
  AND provider = :provider
  AND status = 'success'
  AND fetched_at BETWEEN :day_start AND :day_end
LIMIT 1
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'location_id' => $locationId,
            'provider' => $provider,
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function recordRun(int $locationId, string $provider, string $status, ?string $errorMessage = null): int
    {
        $sql = <<<'SQL'
INSERT INTO forecast_runs (location_id, provider, status, error_message, fetched_at)
VALUES (:location_id, :provider, :status, :error_message, NOW())
RETURNING id
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'location_id' => $locationId,
            'provider' => $provider,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function saveForecast(int $locationId, int $runId, NormalizedForecast $forecast): void
    {
        $this->pdo->beginTransaction();

        try {
            $hourlySql = <<<'SQL'
INSERT INTO hourly_forecasts (
    location_id, provider, run_id, valid_at, temperature_c, precipitation_mm, fetched_at
)
VALUES (
    :location_id, :provider, :run_id, :valid_at, :temperature_c, :precipitation_mm, NOW()
)
ON CONFLICT (location_id, provider, valid_at) DO UPDATE
SET run_id = EXCLUDED.run_id,
    temperature_c = EXCLUDED.temperature_c,
    precipitation_mm = EXCLUDED.precipitation_mm,
    fetched_at = NOW()
SQL;
            $hourlyStmt = $this->pdo->prepare($hourlySql);

            foreach ($forecast->hourly as $point) {
                $hourlyStmt->execute([
                    'location_id' => $locationId,
                    'provider' => $forecast->provider,
                    'run_id' => $runId,
                    'valid_at' => $point->validAtUtc->format('Y-m-d H:i:sP'),
                    'temperature_c' => round($point->temperatureC, 2),
                    'precipitation_mm' => round($point->precipitationMm, 2),
                ]);
            }

            $dailySql = <<<'SQL'
INSERT INTO daily_forecasts (
    location_id, provider, run_id, forecast_date, temp_min_c, temp_max_c, precipitation_mm, fetched_at
)
VALUES (
    :location_id, :provider, :run_id, :forecast_date, :temp_min_c, :temp_max_c, :precipitation_mm, NOW()
)
ON CONFLICT (location_id, provider, forecast_date) DO UPDATE
SET run_id = EXCLUDED.run_id,
    temp_min_c = EXCLUDED.temp_min_c,
    temp_max_c = EXCLUDED.temp_max_c,
    precipitation_mm = EXCLUDED.precipitation_mm,
    fetched_at = NOW()
SQL;
            $dailyStmt = $this->pdo->prepare($dailySql);

            foreach ($forecast->daily as $day) {
                $dailyStmt->execute([
                    'location_id' => $locationId,
                    'provider' => $forecast->provider,
                    'run_id' => $runId,
                    'forecast_date' => $day->forecastDateUtc->format('Y-m-d'),
                    'temp_min_c' => $day->tempMinC !== null ? round($day->tempMinC, 2) : null,
                    'temp_max_c' => $day->tempMaxC !== null ? round($day->tempMaxC, 2) : null,
                    'precipitation_mm' => round($day->precipitationMm, 2),
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryCounts(): array
    {
        return [
            'locations' => (int) $this->pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn(),
            'forecast_runs' => (int) $this->pdo->query('SELECT COUNT(*) FROM forecast_runs')->fetchColumn(),
            'hourly_forecasts' => (int) $this->pdo->query('SELECT COUNT(*) FROM hourly_forecasts')->fetchColumn(),
            'daily_forecasts' => (int) $this->pdo->query('SELECT COUNT(*) FROM daily_forecasts')->fetchColumn(),
        ];
    }
}