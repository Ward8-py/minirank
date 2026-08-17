<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/session.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/keyword.php';

function minirank_create_forbidden(): void
{
    header('HTTP/1.1 403 Forbidden');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Forbidden</title></head><body>'
        . '<h1>Forbidden.</h1>'
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
        minirank_create_forbidden();
    }

    $phrase = isset($_POST['phrase']) && is_string($_POST['phrase'])
        ? $_POST['phrase']
        : '';
    $validated = minirank_validate_phrase($phrase);
    if (!$validated['ok']) {
        minirank_flash_set('error', $validated['error']);
        header('Location: ../index.php', true, 303);
        exit;
    }

    $result = minirank_create_keyword(minirank_db(), $validated['phrase']);
    if (!$result['ok']) {
        minirank_flash_set('error', $result['error']);
        header('Location: ../index.php', true, 303);
        exit;
    }

    minirank_flash_set('success', 'Keyword added.');
    header('Location: ../index.php', true, 303);
    exit;
} catch (Throwable $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Something went wrong</title></head><body>'
        . '<h1>Something went wrong.</h1><p>Please try again later.</p></body></html>';
    exit;
}