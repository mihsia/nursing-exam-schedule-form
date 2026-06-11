<?php
declare(strict_types=1);

function app_config(): array
{
    $configPath = __DIR__ . '/config.php';
    if (!is_file($configPath)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => '尚未建立 api/config.php，請先複製 config.sample.php 並填入資料庫設定。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return require $configPath;
}

function app_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    if (!empty($config['db_socket'])) {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
            $config['db_socket'],
            $config['db_name']
        );
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'] ?? 3306,
            $config['db_name']
        );
    }
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function app_start_session(): void
{
    $config = app_config();
    session_name($config['session_name'] ?? 'NURSING_EXAM_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
