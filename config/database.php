<?php
/**
 * WaterBonia - Database Configuration
 * PDO connection to MySQL via XAMPP localhost
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', getenv('WATERBONIA_DB_PORT') ?: '3306');
define('DB_NAME', 'waterbonia');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Keep MySQL's NOW() in sync with PHP's clock. All application
        // timestamps are written with date(), so the DB session must use
        // the same offset — otherwise NOW()-based logic (e.g. command
        // expiry) evaluates against a different time than the app writes.
        $offset = date('P');
        if ($offset && $offset !== '+00:00') {
            $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
        } else {
            $pdo->exec("SET time_zone = '+00:00'");
        }
    }
    return $pdo;
}
