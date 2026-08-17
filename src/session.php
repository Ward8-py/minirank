<?php

declare(strict_types=1);

function minirank_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function minirank_csrf_token(): string
{
    minirank_session_start();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function minirank_csrf_verify(string $token): bool
{
    minirank_session_start();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function minirank_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(minirank_csrf_token(), ENT_QUOTES, 'UTF-8')
        . '">';
}

function minirank_flash_set(string $type, string $message): void
{
    minirank_session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function minirank_flash_pull(): array
{
    minirank_session_start();
    if (isset($_SESSION['flash']) && is_array($_SESSION['flash'])
        && isset($_SESSION['flash']['type'], $_SESSION['flash']['message'])
        && is_string($_SESSION['flash']['type'])
        && is_string($_SESSION['flash']['message'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return [];
}

function minirank_flash_render(array $flash): string
{
    if ($flash === []
        || !isset($flash['type'], $flash['message'])
        || !is_string($flash['type'])
        || !is_string($flash['message'])) {
        return '';
    }
    $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');
    return '<p class="alert alert-' . $type . '">' . $message . '</p>';
}