<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/session.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/keyword.php';

function minirank_update_forbidden(): void
{
    header('HTTP/1.1 403 Forbidden');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Forbidden</title></head><body>'
        . '<h1>Forbidden.</h1>'
        . '<p><a href="../index.php">Back to keyword list</a></p>'
        . '</body></html>';
    exit;
}

function minirank_update_not_found(): void
{
    header('HTTP/1.1 404 Not Found');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Keyword not found</title></head><body>'
        . '<h1>Keyword not found.</h1>'
        . '<p><a href="../index.php">Back to keyword list</a></p>'
        . '</body></html>';
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        header('HTTP/1.1 405 Method Not Allowed');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Method not allowed</title></head><body>'
            . '<h1>Method not allowed.</h1>'
            . '<p><a href="../index.php">Back to keyword list</a></p>'
            . '</body></html>';
        exit;
    }

    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';
    if (!minirank_csrf_verify($token)) {
        minirank_update_forbidden();
    }

    $id = minirank_parse_id($_POST['id'] ?? null);
    if ($id === null) {
        minirank_update_not_found();
    }

    $phrase = isset($_POST['phrase']) && is_string($_POST['phrase'])
        ? $_POST['phrase']
        : '';
    $result = minirank_update_keyword(minirank_db(), $id, $phrase);
    if (!$result['ok']) {
        if ($result['not_found']) {
            minirank_update_not_found();
        }
        minirank_flash_set('error', $result['error']);
        header('Location: ../keyword-edit.php?id=' . $id, true, 303);
        exit;
    }

    minirank_flash_set('success', 'Keyword updated.');
    header('Location: ../keyword.php?id=' . $id, true, 303);
    exit;
} catch (Throwable $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Something went wrong</title></head><body>'
        . '<h1>Something went wrong.</h1><p>Please try again later.</p></body></html>';
    exit;
}