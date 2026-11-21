<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$pdo = Database::connect();

$locations = $pdo->query('SELECT id, name, latitude, longitude, timezone FROM locations ORDER BY id ASC')->fetchAll();

$selectedLocationId = isset($_GET['location_id']) && is_numeric($_GET['location_id'])
    ? (int) $_GET['location_id']
    : ($locations[0]['id'] ?? null);

$selectedProvider = isset($_GET['provider']) && is_string($_GET['provider']) && $_GET['provider'] !== ''
    ? $_GET['provider']
    : 'all';

$daysLimit = isset($_GET['days']) && is_numeric($_GET['days'])
    ? max(1, min(7, (int) $_GET['days']))
    : 1;

$dailyRows = [];
$hourlyRows = [];
$machineTz = new DateTimeZone(date_default_timezone_get());
$machineNow = new DateTimeImmutable('now', $machineTz);
$startLocal = $machineNow->setTime(0, 0, 0);
$endLocalExclusive = $startLocal->modify("+{$daysLimit} days");

$startUtcStr = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$endUtcStr = $endLocalExclusive->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$startDateStr = $startLocal->format('Y-m-d');
$endDateStr = $endLocalExclusive->format('Y-m-d');

if ($selectedLocationId !== null) {
    $dailySql = '
        SELECT forecast_date, provider, temp_min_c, temp_max_c, precipitation_mm
        FROM daily_forecasts
        WHERE location_id = :location_id
          AND forecast_date >= :start_date
          AND forecast_date < :end_date
    ';
    if ($selectedProvider !== 'all') {
        $dailySql .= ' AND provider = :provider';
    }
    $dailySql .= ' ORDER BY forecast_date ASC, provider ASC';

    $stmtDaily = $pdo->prepare($dailySql);
    $stmtDaily->bindValue('location_id', $selectedLocationId, PDO::PARAM_INT);
    $stmtDaily->bindValue('start_date', $startDateStr, PDO::PARAM_STR);
    $stmtDaily->bindValue('end_date', $endDateStr, PDO::PARAM_STR);
    if ($selectedProvider !== 'all') {
        $stmtDaily->bindValue('provider', $selectedProvider, PDO::PARAM_STR);
    }
    $stmtDaily->execute();
    $dailyRows = $stmtDaily->fetchAll();

    $hourlySql = '
        SELECT valid_at, provider, temperature_c, precipitation_mm
        FROM hourly_forecasts
        WHERE location_id = :location_id
          AND valid_at >= :start_utc
          AND valid_at < :end_utc
    ';
    if ($selectedProvider !== 'all') {
        $hourlySql .= ' AND provider = :provider';
    }
    $hourlySql .= ' ORDER BY valid_at ASC, provider ASC';

    $stmtHourly = $pdo->prepare($hourlySql);
    $stmtHourly->bindValue('location_id', $selectedLocationId, PDO::PARAM_INT);
    $stmtHourly->bindValue('start_utc', $startUtcStr, PDO::PARAM_STR);
    $stmtHourly->bindValue('end_utc', $endUtcStr, PDO::PARAM_STR);
    if ($selectedProvider !== 'all') {
        $stmtHourly->bindValue('provider', $selectedProvider, PDO::PARAM_STR);
    }
    $stmtHourly->execute();
    $hourlyRows = $stmtHourly->fetchAll();
}
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Weather Forecasts Viewer</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; margin: 2rem; background: #0f172a; color: #e2e8f0; }
    h1, h2 { margin-top: 0; color: #f8fafc; }
    form.filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: end; background: #1e293b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
    label { display: flex; flex-direction: column; font-size: 0.875rem; gap: 0.25rem; color: #cbd5e1; }
    select, button { padding: 0.45rem 0.75rem; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #f8fafc; }
    button { background: #2563eb; border-color: #2563eb; cursor: pointer; font-weight: 600; }
    button:hover { background: #1d4ed8; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; background: #1e293b; border-radius: 8px; overflow: hidden; }
    th, td { padding: 0.6rem 0.8rem; text-align: left; border-bottom: 1px solid #334155; font-size: 0.9rem; }
    th { background: #0f172a; color: #94a3b8; font-weight: 600; }
    .muted { color: #94a3b8; font-size: 0.85rem; }
  </style>
</head>
<body>
  <h1>Stored Weather Forecasts</h1>
  <p class="muted">Read-only view of normalized forecasts in PostgreSQL (UTC timestamps, °C, mm). Run <code>bin/fetch-forecasts.php</code> to refresh data.</p>

  <form class="filters" method="get">
    <label>
      Location
      <select name="location_id">
        <?php foreach ($locations as $loc): ?>
          <option value="<?= h($loc['id']) ?>" <?= (int) $loc['id'] === $selectedLocationId ? 'selected' : '' ?>>
            #<?= h($loc['id']) ?> — <?= h($loc['name']) ?> (<?= h($loc['timezone']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Provider
      <select name="provider">
        <option value="all" <?= $selectedProvider === 'all' ? 'selected' : '' ?>>All providers</option>
        <option value="open_meteo" <?= $selectedProvider === 'open_meteo' ? 'selected' : '' ?>>open_meteo</option>
        <option value="weatherapi" <?= $selectedProvider === 'weatherapi' ? 'selected' : '' ?>>weatherapi</option>
      </select>
    </label>

    <label>
      Window (days)
      <select name="days">
        <?php for ($d = 1; $d <= 7; $d++): ?>
          <option value="<?= $d ?>" <?= $daysLimit === $d ? 'selected' : '' ?>><?= $d ?></option>
        <?php endfor; ?>
      </select>
    </label>

    <button type="submit">Apply</button>
  </form>

  <h2>Daily Forecasts (Min–Max °C, Precipitation mm)</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Provider</th>
        <th>Temp Min</th>
        <th>Temp Max</th>
        <th>Precipitation</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($dailyRows === []): ?>
        <tr><td colspan="5" class="muted">No daily forecast rows found.</td></tr>
      <?php else: ?>
        <?php foreach ($dailyRows as $row): ?>
          <tr>
            <td><?= h($row['forecast_date']) ?></td>
            <td><?= h($row['provider']) ?></td>
            <td><?= $row['temp_min_c'] !== null ? h(number_format((float) $row['temp_min_c'], 1)) . ' °C' : 'n/a' ?></td>
            <td><?= $row['temp_max_c'] !== null ? h(number_format((float) $row['temp_max_c'], 1)) . ' °C' : 'n/a' ?></td>
            <td><?= h(number_format((float) $row['precipitation_mm'], 2)) ?> mm</td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <h2>Hourly Forecasts (Single Point °C, Precipitation mm)</h2>
  <table>
    <thead>
      <tr>
        <th>Valid At (UTC)</th>
        <th>Provider</th>
        <th>Temperature</th>
        <th>Precipitation</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($hourlyRows === []): ?>
        <tr><td colspan="4" class="muted">No hourly forecast rows found.</td></tr>
      <?php else: ?>
        <?php foreach ($hourlyRows as $row): ?>
          <tr>
            <td><?= h($row['valid_at']) ?></td>
            <td><?= h($row['provider']) ?></td>
            <td><?= h(number_format((float) $row['temperature_c'], 1)) ?> °C</td>
            <td><?= h(number_format((float) $row['precipitation_mm'], 2)) ?> mm</td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
