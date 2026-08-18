<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/session.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/refresh.php';

const MINIRANK_REFRESH_JSON_FLAGS =
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

function minirank_refresh_json(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], MINIRANK_REFRESH_JSON_FLAGS);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        minirank_refresh_json(405, 'Method not allowed.');
    }

    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';
    if (!minirank_csrf_verify($token)) {
        minirank_refresh_json(403, 'Forbidden.');
    }

    $query = isset($_POST['q']) && is_string($_POST['q']) ? trim($_POST['q']) : '';
    $movement = minirank_parse_movement($_POST);

    $result = minirank_refresh(minirank_db(), null, $query, $movement);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => true, 'refreshed' => $result['refreshed'], 'keywords' => $result['keywords']],
        MINIRANK_REFRESH_JSON_FLAGS
    );
    exit;
} catch (Throwable $e) {
    minirank_refresh_json(500, 'Something went wrong. Please try again later.');
}