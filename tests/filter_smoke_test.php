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
        . 'minirank_filter_smoke_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        . 'minirank_filter_smoke_out_' . bin2hex(random_bytes(4)) . '.log';
    $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_filter_smoke_err_' . bin2hex(random_bytes(4)) . '.log';
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
    $GLOBALS['_filter_smoke_logs'] = array_merge(
        $GLOBALS['_filter_smoke_logs'] ?? [],
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
    foreach ($GLOBALS['_filter_smoke_logs'] ?? [] as $file) {
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
    $GLOBALS['_filter_smoke_logs'] = [];
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

function jsonBody(string $body): ?array
{
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function rowCount(string $html): int
{
    return substr_count($html, 'data-keyword-id=');
}

$docroot = __DIR__ . '/../public';
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'minirank_filter_sessions_' . bin2hex(random_bytes(4));
mkdir($sessionDir, 0700, true);
$today = date('Y-m-d');
$baseline = date('Y-m-d', strtotime($today . ' -7 days'));

$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();
minirank_seed($db);

$improvedId = insertKeyword($db, 'smoke improved kw');
$db->prepare(
    'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)'
)->execute([':k' => $improvedId, ':d' => $baseline, ':p' => 50]);
$db->prepare(
    'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)'
)->execute([':k' => $improvedId, ':d' => $today, ':p' => 30]);
$declinedId = insertKeyword($db, 'smoke declined kw');
$db->prepare(
    'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)'
)->execute([':k' => $declinedId, ':d' => $baseline, ':p' => 30]);
$db->prepare(
    'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)'
)->execute([':k' => $declinedId, ':d' => $today, ':p' => 50]);
$stableId = insertKeyword($db, 'smoke stable kw');
$db->prepare(
    'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)'
)->execute([':k' => $stableId, ':d' => $baseline, ':p' => 40]);
$db->prepare(
    'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)'
)->execute([':k' => $stableId, ':d' => $today, ':p' => 40]);
insertKeyword($db, 'smoke fresh kw');

$totalKeywords = dbKeywordCount($scratch);

$db = null;
minirank_db_close();
gc_collect_cycles();

echo "## Smoke: list page renders the movement filter and results container\n";
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
    strpos($body, 'name="movement"') !== false && strpos($body, 'id="movement"') !== false,
    'list page renders the movement select'
);
report(strpos($body, '<section id="results">') !== false, 'list page renders the stable results container');
report(rowCount($body) === $totalKeywords, 'results container holds the full table of ' . $totalKeywords . ' rows');
$cookie = sessionCookieFrom($headers);
$token = extractToken($body);
report($cookie !== '' && $token !== '', 'session cookie and csrf token extracted');

echo "\n## Smoke: movement filter on the list page\n";
[$status, , $body] = httpGet($base . '/?movement=improved');
report($status === 200, 'improved-filtered page responds 200');
report(
    strpos($body, 'smoke improved kw') !== false && strpos($body, 'smoke declined kw') === false,
    'improved filter shows only the improved keyword'
);
[$status, , $body] = httpGet($base . '/?movement=declined');
report(
    strpos($body, 'smoke declined kw') !== false && strpos($body, 'smoke improved kw') === false,
    'declined filter shows only the declined keyword'
);
[$status, , $body] = httpGet($base . '/?movement=stable');
report(
    strpos($body, 'smoke stable kw') !== false && strpos($body, 'smoke fresh kw') === false,
    'stable filter shows the real-baseline stable keyword and excludes no-history rows'
);
[$status, , $body] = httpGet($base . '/?movement=not_enough_history');
report(
    strpos($body, 'smoke fresh kw') !== false && strpos($body, 'smoke stable kw') === false,
    'not_enough_history filter shows the fresh keyword and excludes the stable keyword'
);

echo "\n## Smoke: malformed filter input leaves the page untouched\n";
[$status, , $body] = httpGet($base . '/?movement=banana');
report(
    $status === 200
        && rowCount($body) === $totalKeywords
        && strpos($body, 'smoke fresh kw') !== false
        && strpos($body, 'smoke improved kw') !== false,
    'unknown movement value leaves the full table intact'
);
[$status, , $body] = httpGet($base . '/?movement[]=improved');
report(
    $status === 200 && rowCount($body) === $totalKeywords,
    'array movement parameter is inert and leaves the full table intact'
);
[$status, , $body] = httpGet($base . '/?q[]=smoke');
report(
    $status === 200 && rowCount($body) === $totalKeywords,
    'array search parameter is inert and leaves the full table intact'
);

echo "\n## Smoke: search and movement combine\n";
[$status, , $body] = httpGet($base . '/?q=smoke');
report(
    $status === 200 && rowCount($body) === 4,
    'search "smoke" alone returns the four smoke keywords'
);
[$status, , $body] = httpGet($base . '/?q=smoke&movement=improved');
report(
    $status === 200
        && rowCount($body) === 1
        && strpos($body, 'smoke improved kw') !== false
        && strpos($body, 'smoke declined kw') === false,
    'search "smoke" + improved returns only the improved smoke keyword'
);
[$status, , $body] = httpGet($base . '/?q=smoke&movement=not_enough_history');
report(
    $status === 200
        && rowCount($body) === 1
        && strpos($body, 'smoke fresh kw') !== false,
    'search "smoke" + not_enough_history returns only the fresh smoke keyword'
);
[$status, , $body] = httpGet($base . '/?q=zzz&movement=improved');
report(
    $status === 200
        && rowCount($body) === 0
        && strpos($body, 'No keywords match your search.') !== false,
    'no-match search renders the no-results message inside the container'
);

echo "\n## Smoke: refresh honors the active movement filter\n";
$before = $totalKeywords;
[$status, $headers, $body] = httpPost(
    $base . '/actions/refresh.php',
    ['csrf_token' => $token, 'movement' => 'improved'],
    $cookie
);
report($status === 200, 'filtered refresh POST returns 200');
report(strpos(contentTypeOf($headers), 'application/json') !== false, 'filtered refresh response is JSON');
report(locationHeader($headers) === '', 'filtered refresh response has no Location header');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === true && $data['refreshed'] === $totalKeywords,
    'refresh JSON reports the total refreshed count, separate from the filtered count'
);
report(
    is_array($data) && isset($data['keywords']) && count($data['keywords']) <= $data['refreshed'],
    'refresh JSON carries a filtered keywords array no larger than the total'
);
$hasImproved = false;
$allImproved = true;
foreach (is_array($data) ? ($data['keywords'] ?? []) : [] as $kw) {
    if ($kw['direction'] === 'improved') {
        $hasImproved = true;
    } else {
        $allImproved = false;
    }
}
report($hasImproved && $allImproved, 'filtered refresh payload contains only improved keywords');
report(
    dbKeywordCount($scratch) === $before,
    'filtered refresh mutates the database but never the keyword count'
);

echo "\n## Smoke: unfiltered refresh keeps M3 behavior\n";
[$status, , $body] = httpPost(
    $base . '/actions/refresh.php',
    ['csrf_token' => $token],
    $cookie
);
report($status === 200, 'unfiltered refresh POST returns 200');
$data = jsonBody($body);
report(
    is_array($data)
        && $data['refreshed'] === $totalKeywords
        && count($data['keywords']) === $totalKeywords,
    'unfiltered refresh reports total and covers every keyword'
);
report(
    strpos($body, '<script') === false,
    'raw script tag absent from the JSON response body'
);

echo "\n## Smoke: refresh endpoint guards\n";
[$status, $headers, $body] = httpGet($base . '/actions/refresh.php', $cookie);
report($status === 405, 'GET on the refresh endpoint returns 405');
report(
    stripos(implode("\n", $headers), 'allow: post') !== false,
    '405 response advertises Allow: POST'
);
[$status, , $body] = httpPost($base . '/actions/refresh.php', []);
report($status === 403, 'POST without csrf token returns 403');
$data = jsonBody($body);
report(
    is_array($data) && $data['ok'] === false && $data['error'] === 'Forbidden.',
    'missing-csrf 403 JSON carries ok:false and a safe error message'
);

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