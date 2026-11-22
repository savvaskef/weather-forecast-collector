<?php
declare(strict_types=1);

function getOrCreateLocation(
    PDO $pdo,
    string $name,
    float $latitude,
    float $longitude,
    string $timezone = 'Europe/Athens'
): int {
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Location name is required.');
    }

    // 1. Check if the location already exists (case-insensitive name match)
    $select = $pdo->prepare(
        'SELECT id FROM locations WHERE LOWER(name) = LOWER(:name) LIMIT 1'
    );
    $select->execute([':name' => $name]);
    $existingId = $select->fetchColumn();

    if ($existingId !== false) {
        return (int) $existingId;
    }

    // 2. Insert if not found
    $insert = $pdo->prepare(
        'INSERT INTO locations (name, latitude, longitude, timezone)
         VALUES (:name, :latitude, :longitude, :timezone)
         RETURNING id'
    );
    $insert->execute([
        ':name'      => $name,
        ':latitude'  => $latitude,
        ':longitude' => $longitude,
        ':timezone'  => $timezone,
    ]);

    return (int) $insert->fetchColumn();
}

function listLocations(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, name, latitude, longitude, timezone, created_at
         FROM locations
         ORDER BY name ASC'
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}