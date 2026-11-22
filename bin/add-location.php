<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php'; // adjust if you don't use Composer autoload
require_once __DIR__ . '/../src/LocationRepository.php';

// Replace with your existing getPdo() helper if you already have one
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    getenv('DB_HOST') ?: 'db',
    getenv('DB_PORT') ?: '5432',
    getenv('DB_NAME') ?: 'weather_db'
);
$pdo = new PDO(
    $dsn,
    getenv('DB_USER') ?: 'weather_user',
    getenv('DB_PASSWORD') ?: 'the_passw',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Usage: php bin/add-location.php "Thessaloniki" 40.6401 22.9444 "Europe/Athens"
$name      = $argv[1] ?? '';
$latitude  = isset($argv[2]) ? (float) $argv[2] : null;
$longitude = isset($argv[3]) ? (float) $argv[3] : null;
$timezone  = $argv[4] ?? 'Europe/Athens';

if ($name === '' || $latitude === null || $longitude === null) {
    fwrite(STDERR, "Usage: php bin/add-location.php <name> <latitude> <longitude> [timezone]\n");
    exit(1);
}

$id = getOrCreateLocation($pdo, $name, $latitude, $longitude, $timezone);
echo "Location ready: {$name} (id={$id})\n";