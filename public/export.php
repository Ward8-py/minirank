<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/keyword.php';
require_once __DIR__ . '/../src/export.php';

function minirank_export_404(): void
{
    header('HTTP/1.1 404 Not Found');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Keyword not found</title></head><body>'
        . '<h1>Keyword not found.</h1>'
        . '<p><a href="index.php">Back to keyword list</a></p>'
        . '</body></html>';
    exit;
}

try {
    $id = minirank_parse_id($_GET['id'] ?? null);
    if ($id === null) {
        minirank_export_404();
    }

    $db = minirank_db();
    $keyword = minirank_find_keyword($db, $id);
    if ($keyword === null) {
        minirank_export_404();
    }

    $history = minirank_position_history($db, $id);
    $csv = minirank_csv_rows($keyword, $history);
    $filename = minirank_export_filename($id);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
} catch (Throwable $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Something went wrong</title></head><body>'
        . '<h1>Something went wrong.</h1><p>Please try again later.</p></body></html>';
    exit;
}