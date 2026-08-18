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
        . 'minirank_refresh_smoke_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function insertKeyword(PDO $pdo, string $phrase): int
{
    $pdo->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')
        ->execute([':phrase' => $phrase]);
    return (int) $pdo->lastInsertId();
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
        . 'minirank_refresh_smoke_out_' . bin2hex(random_bytes(4)) . '.log';
    $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_refresh_smoke_err_' . bin2hex(random_bytes(4)) . '.log';
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
    $GLOBALS['_refresh_smoke_logs'] = array_merge(
        $GLOBALS['_refresh_smoke_logs'] ?? [],
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
    foreach ($GLOBALS['_refresh_smoke_logs'] ?? [] as $file) {
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
    $GLOBALS['_refresh_smoke_logs'] = [];
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

function contentTypeOf(array $headers): string
{
    foreach ($headers as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            return strtolower(trim(substr($line, strlen('Content-Type:'))));
        }
    }
    return '';
}

function dbPdo(string $path): PDO
{
    return new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function dbKeywordCount(string $path): int
{
    $pdo = dbPdo($path);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM keywords')->fetchColumn();
    $pdo = null;
    return $count;
}

function dbTodayPositionCount(string $path, string $date): int
{
    $pdo = dbPdo($path);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM positions WHERE recorded_on = :d');
    $stmt->execute([':d' => $date]);
    $count = (int) $stmt->fetchColumn();
    $pdo = null;
    return $count;
}

function jsonBody(string $body): ?array
{
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

$docroot = __DIR__ . '/../public';
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'minirank_sessions_' . bin2hex(random_bytes(4));
mkdir($sessionDir, 0700, true);
$today = date('Y-m-d');

$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();
minirank_seed($db);

$noHistoryId = insertKeyword($db, 'fresh keyword no history');
$hostileId = insertKeyword($db, '<script>alert("xss")</script>');
$totalKeywords = dbKeywordCount($scratch);

$db = null;
minirank_db_close();
gc_collect_cycles();

echo "## Smoke: refresh control on the list page\n";
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
    strpos($body, 'id="refresh-positions"') !== false
        && strpos($body, 'id="refresh-csrf"') !== false,
    'list page renders the refresh control with token input'
);
report(strpos($body, 'js/refresh.js') !== false, 'list page loads the refresh script');
$cookie = sessionCookieFrom($headers);
$token = extractToken($body);
report($cookie !== '' && $token !== '', 'session cookie and csrf token extracted');

echo "\n## Smoke: wrong method returns 405 JSON without mutation\n";
$before = dbTodayPositionCount($scratch, $today);
[$status, $headers, $body] = httpGet($base . '/actions/refresh.php', $cookie);
report($status === 405, 'GET on the refresh endpoint returns 405');
report(
    stripos(implode("\n", $headers), 'allow: post') !== false,
    '405 response advertises Allow: POST'
);
report(strpos(contentTypeOf($headers), 'application/json') !== false, '405 body is JSON');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === false && $data['error'] === 'Method not allowed.',
    '405 JSON carries ok:false and a safe error message'
);
report(dbTodayPositionCount($scratch, $today) === $before, 'GET on refresh adds no today rows');

echo "\n## Smoke: missing or wrong csrf returns 403 JSON without mutation\n";
[$status, , $body] = httpPost($base . '/actions/refresh.php', []);
report($status === 403, 'POST without csrf token returns 403');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === false && $data['error'] === 'Forbidden.',
    'missing-csrf 403 JSON carries ok:false and a safe error message'
);
[$status, , $body] = httpPost(
    $base . '/actions/refresh.php',
    ['csrf_token' => 'deadbeefdeadbeef'],
    $cookie
);
report($status === 403, 'POST with wrong csrf token returns 403');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === false && $data['error'] === 'Forbidden.',
    'wrong-csrf 403 JSON carries ok:false and a safe error message'
);
report(dbTodayPositionCount($scratch, $today) === $before, 'failed csrf attempts add no today rows');

echo "\n## Smoke: valid refresh returns JSON with no navigation\n";
[$status, $headers, $body] = httpPost(
    $base . '/actions/refresh.php',
    ['csrf_token' => $token],
    $cookie
);
report($status === 200, 'valid refresh POST returns 200');
report(strpos(contentTypeOf($headers), 'application/json') !== false, 'refresh response is JSON');
report(locationHeader($headers) === '', 'refresh response has no Location header (no redirect)');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === true && $data['refreshed'] === $totalKeywords,
    'refresh JSON reports ok:true and the full keyword count'
);
report(isset($data['keywords']) && is_array($data['keywords']), 'refresh JSON carries a keywords array');
report(count($data['keywords']) === $totalKeywords, 'keywords array covers every keyword');
$allValid = true;
$noHistorySeen = false;
$hostileSeen = false;
foreach ($data['keywords'] as $kw) {
    if (!isset($kw['id'], $kw['phrase'], $kw['current_position'], $kw['direction'])
        || !is_int($kw['current_position'])
        || $kw['current_position'] < 1 || $kw['current_position'] > 100) {
        $allValid = false;
    }
    if ((int) $kw['id'] === $noHistoryId) {
        $noHistorySeen = $kw['not_enough_history'] === true
            && $kw['baseline_position'] === null;
    }
    if ((int) $kw['id'] === $hostileId) {
        $hostileSeen = $kw['phrase'] === '<script>alert("xss")</script>';
    }
}
report($allValid, 'every keyword has an int current_position within 1..100 and a direction');
report($noHistorySeen, 'no-history keyword now reports a current position with not enough history');
report($hostileSeen, 'hostile-phrase keyword is present in the JSON payload');
report(strpos($body, '<script') === false, 'raw script tag absent from the JSON response body');
report(dbTodayPositionCount($scratch, $today) === $totalKeywords, 'exactly one today row per keyword persisted');

echo "\n## Smoke: repeated refresh stays one row per keyword per day\n";
[$status, , $body] = httpPost(
    $base . '/actions/refresh.php',
    ['csrf_token' => $token],
    $cookie
);
report($status === 200, 'second refresh POST returns 200');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === true,
    'second refresh reports ok:true'
);
$allInRange = true;
foreach ($data['keywords'] as $kw) {
    if ($kw['current_position'] < 1 || $kw['current_position'] > 100) {
        $allInRange = false;
    }
}
report($allInRange, 'repeated refresh keeps every current position within 1..100');
report(dbTodayPositionCount($scratch, $today) === $totalKeywords, 'repeated refresh leaves one today row per keyword');

stopServer($proc);
$proc = null;

echo "\n## Smoke: generic 500 JSON on unopenable database\n";
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

[$status, $headers, $body] = httpPost(
    $base2 . '/actions/refresh.php',
    ['csrf_token' => $token],
    $cookie
);
report($status === 500, 'refresh POST responds 500 when the database cannot open');
report(strpos(contentTypeOf($headers), 'application/json') !== false, '500 response is JSON');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === false,
    '500 JSON carries ok:false'
);
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