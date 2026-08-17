<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/seed.php';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function report(bool $ok, string $label): void
{
    if ($ok) {
        $GLOBALS['passed']++;
        echo "PASS  $label\n";
    } else {
        $GLOBALS['failed']++;
        echo "FAIL  $label\n";
    }
}

function scratchPath(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_create_smoke_' . bin2hex(random_bytes(4)) . '.sqlite';
}

function cleanup(string $path): void
{
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (!is_file($file)) {
            continue;
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@unlink($file)) {
                break;
            }
            usleep(100000);
        }
    }
}

function removeDirTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $file = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($file)) {
            @unlink($file);
        } elseif (is_dir($file)) {
            removeDirTree($file);
        }
    }
    @rmdir($dir);
}

function freePort(): ?int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return null;
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($name) || preg_match('/:(\d+)$/', $name, $m) !== 1) {
        return null;
    }
    return (int) $m[1];
}

function startServer(string $docroot, array $iniArgs = []): array
{
    $port = freePort();
    if ($port === null) {
        return [null, null];
    }
    $outFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_create_smoke_out_' . bin2hex(random_bytes(4)) . '.log';
    $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_create_smoke_err_' . bin2hex(random_bytes(4)) . '.log';
    $cmd = escapeshellarg(PHP_BINARY);
    foreach ($iniArgs as $arg) {
        $cmd .= ' -d ' . escapeshellarg($arg);
    }
    $cmd .= ' -S 127.0.0.1:' . $port
        . ' -t ' . escapeshellarg($docroot);
    $pipes = [];
    $proc = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ],
        $pipes
    );
    if (!is_resource($proc)) {
        return [null, null];
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $GLOBALS['_create_smoke_logs'] = array_merge(
        $GLOBALS['_create_smoke_logs'] ?? [],
        [$outFile, $errFile]
    );
    return [$proc, $port];
}

function stopServer($proc): void
{
    if (is_resource($proc)) {
        $status = proc_get_status($proc);
        $pid = is_array($status) && isset($status['pid']) ? (int) $status['pid'] : 0;
        if ($pid > 0) {
            if (PHP_OS_FAMILY === 'Windows') {
                $killOut = [];
                $killRc = 0;
                exec('taskkill /F /PID ' . $pid . ' /T 2>&1', $killOut, $killRc);
            } else {
                proc_terminate($proc);
            }
        }
        proc_close($proc);
    }
    foreach ($GLOBALS['_create_smoke_logs'] ?? [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (@unlink($file)) {
                break;
            }
            usleep(100000);
        }
    }
    $GLOBALS['_create_smoke_logs'] = [];
}

function httpRequest(string $url, array $opts): array
{
    $context = stream_context_create(
        ['http' => $opts + ['ignore_errors' => true, 'follow_location' => 0]]
    );
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $headers = [];
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                $status = (int) $m[1];
            }
            $headers[] = $line;
        }
    }
    return [$status, $headers, $body === false ? '' : $body];
}

function httpGet(string $url, string $cookie = ''): array
{
    $opts = ['method' => 'GET'];
    if ($cookie !== '') {
        $opts['header'] = 'Cookie: ' . $cookie;
    }
    return httpRequest($url, $opts);
}

function httpPost(string $url, array $fields, string $cookie = ''): array
{
    $header = 'Content-Type: application/x-www-form-urlencoded';
    if ($cookie !== '') {
        $header .= "\r\nCookie: " . $cookie;
    }
    return httpRequest($url, [
        'method' => 'POST',
        'header' => $header,
        'content' => http_build_query($fields),
    ]);
}

function httpGetRetry(string $url, string $cookie = ''): array
{
    for ($i = 0; $i < 20; $i++) {
        [$status, $headers, $body] = httpGet($url, $cookie);
        if ($status !== 0) {
            return [$status, $headers, $body];
        }
        usleep(100000);
    }
    return [0, [], ''];
}

function sessionCookieFrom(array $headers): string
{
    foreach ($headers as $line) {
        if (stripos($line, 'Set-Cookie:') === 0
            && preg_match('/PHPSESSID=([^;]+)/i', $line, $m)) {
            return 'PHPSESSID=' . $m[1];
        }
    }
    return '';
}

function extractToken(string $body): string
{
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $body, $m)) {
        return $m[1];
    }
    return '';
}

function locationHeader(array $headers): string
{
    foreach ($headers as $line) {
        if (stripos($line, 'Location:') === 0) {
            return trim(substr($line, strlen('Location:')));
        }
    }
    return '';
}

function dbKeywordCount(string $path): int
{
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM keywords')->fetchColumn();
    $pdo = null;
    return $count;
}

$docroot = __DIR__ . '/../public';
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'minirank_sessions_' . bin2hex(random_bytes(4));
mkdir($sessionDir, 0700, true);

$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();
minirank_seed($db);
$db = null;
minirank_db_close();
gc_collect_cycles();

echo "## Smoke: create endpoint over HTTP\n";
[$proc, $port] = startServer($docroot, ['session.save_path=' . $sessionDir]);
report(is_resource($proc) && $port !== null, 'built-in server starts and binds a chosen port');
if (!is_resource($proc) || $port === null) {
    echo "\n1 passed, 1 failed\n";
    cleanup($scratch);
    removeDirTree($sessionDir);
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;

[$status, $headers, $body] = httpGetRetry($base . '/');
report($status === 200, 'list page responds 200');
report(
    strpos($body, 'action="actions/keyword-create.php"') !== false,
    'add form posts to the dedicated create endpoint'
);
report(strpos($body, 'method="post"') !== false, 'add form uses POST');
report(strpos($body, 'name="csrf_token"') !== false, 'add form embeds a csrf token field');
$cookie = sessionCookieFrom($headers);
$token = extractToken($body);
report($cookie !== '' && $token !== '', 'session cookie and csrf token extracted');

echo "\n## Smoke: missing or wrong csrf returns 403 without mutation\n";
[$status, , $body] = httpPost($base . '/actions/keyword-create.php', ['phrase' => 'csrf blocked']);
report($status === 403, 'POST without csrf token returns 403');
report(strpos($body, 'href="../index.php"') !== false, '403 page links back to the list page');
report(strpos($body, 'href="index.php"') === false, '403 page has no relative link that would 404');
[$status, , ] = httpPost(
    $base . '/actions/keyword-create.php',
    ['csrf_token' => 'deadbeefdeadbeef', 'phrase' => 'csrf blocked'],
    $cookie
);
report($status === 403, 'POST with wrong csrf token returns 403');
report(dbKeywordCount($scratch) === 6, 'failed csrf attempts add no keywords');
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report($status === 200 && strpos($body, 'csrf blocked') === false, 'blocked phrase absent from the list');

echo "\n## Smoke: non-POST requests must not mutate\n";
[$status, , $body] = httpGet($base . '/actions/keyword-create.php', $cookie);
report($status === 405, 'GET on the create endpoint returns 405');
report(strpos($body, 'href="../index.php"') !== false, '405 page links back to the list page');
report(strpos($body, 'href="index.php"') === false, '405 page has no relative link that would 404');
report(dbKeywordCount($scratch) === 6, 'GET on the create endpoint adds no keywords');

echo "\n## Smoke: valid create with 303 redirect\n";
[$status, $headers, ] = httpPost(
    $base . '/actions/keyword-create.php',
    ['csrf_token' => $token, 'phrase' => 'brand new tracker'],
    $cookie
);
report($status === 303, 'valid create POST returns 303');
report(strpos(locationHeader($headers), 'index.php') !== false, '303 redirects back to the list page');
report(dbKeywordCount($scratch) === 7, 'valid create adds exactly one keyword');
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report($status === 200, 'list page responds 200 after redirect');
report(strpos($body, 'brand new tracker') !== false, 'created keyword appears in the list');
report(strpos($body, 'Keyword added.') !== false, 'success flash shown on the list page');

echo "\n## Smoke: blank and oversized rejected\n";
[$status, , ] = httpPost(
    $base . '/actions/keyword-create.php',
    ['csrf_token' => $token, 'phrase' => '   '],
    $cookie
);
report($status === 303, 'blank create POST returns 303');
report(dbKeywordCount($scratch) === 7, 'blank create adds no keyword');
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report(strpos($body, 'Keyword cannot be blank.') !== false, 'blank error flash shown');

[$status, , ] = httpPost(
    $base . '/actions/keyword-create.php',
    ['csrf_token' => $token, 'phrase' => str_repeat('o', 121)],
    $cookie
);
report($status === 303, 'oversized create POST returns 303');
report(dbKeywordCount($scratch) === 7, 'oversized create adds no keyword');
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report(
    strpos($body, '120 characters or fewer') !== false,
    'oversized error flash shown'
);

echo "\n## Smoke: case-insensitive duplicate rejected\n";
[$status, , ] = httpPost(
    $base . '/actions/keyword-create.php',
    ['csrf_token' => $token, 'phrase' => 'SEO TOOLS'],
    $cookie
);
report($status === 303, 'duplicate create POST returns 303');
report(dbKeywordCount($scratch) === 7, 'duplicate create adds no keyword');
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report(
    strpos($body, 'already exists') !== false,
    'duplicate error flash shown'
);
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report(strpos($body, 'already exists') === false, 'flash is one-shot and does not linger');

echo "\n## Smoke: hostile phrase stored and escaped\n";
$hostile = '<script>alert("pwned")</script>';
[$status, , ] = httpPost(
    $base . '/actions/keyword-create.php',
    ['csrf_token' => $token, 'phrase' => $hostile],
    $cookie
);
report($status === 303, 'hostile-phrase create POST returns 303');
report(dbKeywordCount($scratch) === 8, 'hostile phrase stored verbatim as a keyword');
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report($status === 200, 'list page responds 200 with hostile keyword present');
report(strpos($body, '<script>alert') === false, 'raw script tag absent from the rendered list');
report(strpos($body, '&lt;script&gt;alert') !== false, 'hostile phrase displayed escaped as text');

stopServer($proc);
$proc = null;

echo "\n## Smoke: generic 500 on unopenable database\n";
putenv('MINIRANK_DB_PATH=' . sys_get_temp_dir());
[$proc, $port] = startServer($docroot, ['session.save_path=' . $sessionDir]);
report(is_resource($proc) && $port !== null, 'second server starts against an unopenable database path');
if (!is_resource($proc) || $port === null) {
    echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
    cleanup($scratch);
    removeDirTree($sessionDir);
    exit(1);
}
$base2 = 'http://127.0.0.1:' . $port;

[$status, , ] = httpGetRetry($base2 . '/');
report($status === 500, 'list page responds 500 when the database cannot open');
[$status, $headers, $body] = httpPost(
    $base2 . '/actions/keyword-create.php',
    ['csrf_token' => $token, 'phrase' => 'unopenable attempt'],
    $cookie
);
report($status === 500, 'create POST responds 500 when the database cannot open');
report(
    strpos($body, 'SQLSTATE') === false
        && strpos($body, 'PDOException') === false
        && strpos($body, 'unable to open') === false
        && strpos($body, sys_get_temp_dir()) === false,
    '500 body is generic with no exception or path details'
);

stopServer($proc);
$proc = null;
putenv('MINIRANK_DB_PATH');
gc_collect_cycles();
cleanup($scratch);
removeDirTree($sessionDir);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}