<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$opts = getopt('', ['resolution::', 'provider::', 'limit::', 'json']);
$resolution = ($opts['resolution'] ?? 'daily') === 'hourly' ? 'hourly' : 'daily';
$provider = isset($opts['provider']) && is_string($opts['provider']) && $opts['provider'] !== '' ? $opts['provider'] : null;
$limit = isset($opts['limit']) && is_numeric($opts['limit']) ? max(1, min(500, (int) $opts['limit'])) : 24;
$asJson = array_key_exists('json', $opts);

$pdo = Database::connect();

if ($resolution === 'hourly') {
    $sql = <<<'SQL'
SELECT l.name AS location, h.provider, h.valid_at, h.temperature_c, h.precipitation_mm, h.fetched_at
FROM hourly_forecasts h
JOIN locations l ON l.id = h.location_id
WHERE (:provider IS NULL OR h.provider = :provider)
ORDER BY h.valid_at DESC, h.provider ASC
LIMIT :limit
SQL;
} else {
    $sql = <<<'SQL'
SELECT l.name AS location, d.provider, d.forecast_date, d.temp_min_c, d.temp_max_c, d.precipitation_mm, d.fetched_at
FROM daily_forecasts d
JOIN locations l ON l.id = d.location_id
WHERE (:provider IS NULL OR d.provider = :provider)
ORDER BY d.forecast_date DESC, d.provider ASC
LIMIT :limit
SQL;
}

$stmt = $pdo->prepare($sql);
$stmt->bindValue('provider', $provider, $provider === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if ($asJson) {
    echo json_encode(['resolution' => $resolution, 'count' => count($rows), 'data' => $rows], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

echo json_encode($rows, JSON_PRETTY_PRINT) . PHP_EOL;