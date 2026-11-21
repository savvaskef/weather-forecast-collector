<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Forecast\NormalizedForecast;
use App\Provider\OpenMeteoClient;
use App\Provider\WeatherApiClient;
use App\Provider\WeatherProviderInterface;
use App\Storage\ForecastRepository;
// use DateTimeImmutable;
// use DateTimeZone;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$options = getopt('', ['force', 'days::', 'quiet-forecast', 'print-hours::']);
$force = array_key_exists('force', $options);
$days = isset($options['days']) && is_numeric($options['days']) ? max(1, min(7, (int) $options['days'])) : 1;
$printForecast = !array_key_exists('quiet-forecast', $options);
$printHoursLimit = isset($options['print-hours']) && is_numeric($options['print-hours'])
    ? max(1, (int) $options['print-hours'])
    : null;

$machineTz = new DateTimeZone(date_default_timezone_get());
$machineNow = new DateTimeImmutable('now', $machineTz);
$targetLocalDate = $machineNow->format('Y-m-d');
$allowedDates = targetLocalDates($machineNow, $days, $machineTz);
$fetchDays = min(7, $days + 1);

$pdo = Database::connect();
$repo = new ForecastRepository($pdo);

$defaultLatRaw = $_ENV['LOCATION_LAT'] ?? getenv('LOCATION_LAT');
$defaultLonRaw = $_ENV['LOCATION_LON'] ?? getenv('LOCATION_LON');

if (is_string($defaultLatRaw) && $defaultLatRaw !== '' && is_string($defaultLonRaw) && $defaultLonRaw !== '') {
    $defaultName = (string) ($_ENV['LOCATION_NAME'] ?? getenv('LOCATION_NAME') ?: 'Default Location');
    $defaultTz = (string) ($_ENV['LOCATION_TIMEZONE'] ?? getenv('LOCATION_TIMEZONE') ?: $machineTz->getName());
    $repo->ensureLocation($defaultName, (float) $defaultLatRaw, (float) $defaultLonRaw, $defaultTz);
}

$locations = $repo->allLocations();

/** @var list<WeatherProviderInterface> $providers */
$providers = [
    new OpenMeteoClient(),
    new WeatherApiClient((string) ($_ENV['WEATHERAPI_KEY'] ?? getenv('WEATHERAPI_KEY') ?: '')),
];

/**
 * Builds the ordered list of local calendar dates (Y-m-d) in $machineTz
 * starting from $startDate for $numDays days.
 *
 * @return list<string>
 */
function targetLocalDates(DateTimeImmutable $startDate, int $numDays, DateTimeZone $machineTz): array
{
    $dates = [];
    $cursor = $startDate->setTimezone($machineTz)->setTime(0, 0, 0);

    for ($i = 0; $i < $numDays; $i++) {
        $dates[] = $cursor->modify("+{$i} days")->format('Y-m-d');
    }

    return $dates;
}

/**
 * Filters a normalized forecast to the exact target local calendar dates in $machineTz.
 *
 * @param list<string> $allowedDates
 */
function alignForecastToLocalDates(NormalizedForecast $forecast, array $allowedDates, DateTimeZone $machineTz): NormalizedForecast
{
    $allowedSet = array_flip($allowedDates);

    $filteredHourly = array_values(array_filter(
        $forecast->hourly,
        static fn($pt): bool => isset($allowedSet[$pt->validAtUtc->setTimezone($machineTz)->format('Y-m-d')])
    ));

    $filteredDaily = array_values(array_filter(
        $forecast->daily,
        static fn($day): bool => isset($allowedSet[$day->forecastDateUtc->format('Y-m-d')])
    ));

    return new NormalizedForecast(
        provider: $forecast->provider,
        hourly: $filteredHourly,
        daily: $filteredDaily,
    );
}

function printNormalizedForecast(string $locationName, NormalizedForecast $forecast, ?int $hourLimit): void
{
    echo "\n=== {$locationName} | Provider: {$forecast->provider} ===\n";

    echo "Daily Forecast (min-max °C, precip mm):\n";
    foreach ($forecast->daily as $day) {
        $minStr = $day->tempMinC !== null ? sprintf('%.1f°C', $day->tempMinC) : 'n/a';
        $maxStr = $day->tempMaxC !== null ? sprintf('%.1f°C', $day->tempMaxC) : 'n/a';
        echo sprintf(
            "  %s | temp: %s .. %s | precip: %.2f mm\n",
            $day->forecastDateUtc->format('Y-m-d'),
            $minStr,
            $maxStr,
            $day->precipitationMm
        );
    }

    $hours = $hourLimit !== null ? array_slice($forecast->hourly, 0, $hourLimit) : $forecast->hourly;
    echo sprintf("Hourly Forecast (point °C, precip mm) [showing %d of %d]:\n", count($hours), count($forecast->hourly));
    foreach ($hours as $pt) {
        echo sprintf(
            "  %s UTC | temp: %.1f°C | precip: %.2f mm\n",
            $pt->validAtUtc->format('Y-m-d H:i'),
            $pt->temperatureC,
            $pt->precipitationMm
        );
    }
}

$overallSuccesses = 0;
$overallFailures = 0;

foreach ($locations as $location) {
    echo sprintf(
        "\n[Location #%d: %s (%.5f, %.5f) | Machine date: %s (%s) | Days: %d]\n",
        $location->id,
        $location->name,
        $location->latitude,
        $location->longitude,
        $targetLocalDate,
        $machineTz->getName(),
        $days
    );

    foreach ($providers as $provider) {
        $providerKey = $provider->providerKey();

        if (!$force && $repo->hasSuccessfulRunToday($location->id, $providerKey)) {
            echo sprintf("  - %s: skipped (already succeeded today UTC; use --force to re-run)\n", $providerKey);
            $overallSuccesses++;
            continue;
        }

        try {
            $rawForecast = $provider->fetchForecast($location, $fetchDays);
            $aligned = alignForecastToLocalDates($rawForecast, $allowedDates, $machineTz);

            $runId = $repo->recordRun($location->id, $providerKey, 'success', null);
            $repo->saveForecast($location->id, $runId, $aligned);

            echo sprintf(
                "  - %s: saved to DB (run #%d, hourly=%d, daily=%d)\n",
                $providerKey,
                $runId,
                count($aligned->hourly),
                count($aligned->daily)
            );

            if ($printForecast) {
                printNormalizedForecast($location->name, $aligned, $printHoursLimit);
            }

            $overallSuccesses++;
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 1000);
            $repo->recordRun($location->id, $providerKey, 'failed', $msg);
            fwrite(STDERR, sprintf("  - %s: FAILED (%s)\n", $providerKey, $msg));
            $overallFailures++;
        }
    }
}

echo "\nSummary: successes={$overallSuccesses}, failures={$overallFailures}\n";
exit($overallSuccesses > 0 ? 0 : 1);