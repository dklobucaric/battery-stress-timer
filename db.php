<?php
/*
|--------------------------------------------------------------------------
| Battery Stress Timer - Database Helper
|--------------------------------------------------------------------------
|
| PDO database connection helper.
|
| Vibe code by Dalibor Klobučarić & my friend ChatGPT
|
|--------------------------------------------------------------------------
*/

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $db = $config['db'];

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        $db['charset']
    );

    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage()); // log for debugging
        die('Database connection failed. Please check your configuration.');
    }

    return $pdo;
}

function json_response(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_client_ip(): string
{
    $ip =
        $_SERVER['HTTP_CF_CONNECTING_IP'] ??
        $_SERVER['HTTP_X_FORWARDED_FOR'] ??
        $_SERVER['REMOTE_ADDR'] ??
        'unknown';

    // If X-Forwarded-For contains multiple IPs, take the first one.
    if (str_contains($ip, ',')) {
        $parts = explode(',', $ip);
        $ip = trim($parts[0]);
    }

    return substr($ip, 0, 45);
}
