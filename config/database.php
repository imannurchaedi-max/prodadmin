<?php
declare(strict_types=1);

function prodAdminDbConfig(): array
{
    $host = getenv('PRODADMIN_DB_HOST') ?: 'localhost';
    $port = getenv('PRODADMIN_DB_PORT') ?: '5432';
    $name = getenv('PRODADMIN_DB_NAME') ?: 'prod_admin';
    $user = getenv('PRODADMIN_DB_USER') ?: 'postgres';
    $pass = getenv('PRODADMIN_DB_PASS');

    if ($pass === false) {
        $pass = 'SASMU123';
    }

    return [
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
    ];
}

function getDb(): PDO
{
    static $db = null;

    if ($db instanceof PDO) {
        return $db;
    }

    $cfg = prodAdminDbConfig();
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;options=--search_path=public',
        $cfg['host'],
        $cfg['port'],
        $cfg['name']
    );

    try {
        $db = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true,  // reuse connections across requests
        ]);
        $db->exec("SET timezone='Asia/Jakarta'");
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed.',
            'error' => $e->getMessage(),
        ]);
        exit;
    }

    return $db;
}
