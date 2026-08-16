<?php

declare(strict_types=1);

require_once __DIR__ . '/schema.php';

function minirank_db_path(): string
{
    $path = getenv('MINIRANK_DB_PATH');
    if (is_string($path) && $path !== '') {
        return $path;
    }
    return __DIR__ . '/../data/minirank.sqlite';
}

$GLOBALS['_minirank_db'] = null;

function minirank_db(): PDO
{
    if ($GLOBALS['_minirank_db'] instanceof PDO) {
        return $GLOBALS['_minirank_db'];
    }

    $path = minirank_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO(
        'sqlite:' . $path,
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    minirank_schema($pdo);

    $GLOBALS['_minirank_db'] = $pdo;

    return $pdo;
}

function minirank_db_close(): void
{
    $GLOBALS['_minirank_db'] = null;
}