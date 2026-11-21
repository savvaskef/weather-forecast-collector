<?php
declare(strict_types=1);

namespace App\Config;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'db');
        $port = (string) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432');
        $dbName = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'weather_db');
        $user = (string) ($_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'weather_user');
        $pass = (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbName);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}   