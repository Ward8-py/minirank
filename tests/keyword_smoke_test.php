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
        . 'minirank_smoke_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function insertKeyword(PDO $pdo, string $phrase): int
{
    $pdo->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')
        ->execute([':phrase' => $phrase]);
    return (int) $pdo->lastInsertId();
}

function insertPosition(PDO $pdo, int $keywordId, string $date, int $position): void
{
    $pdo->prepare(
        'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)'
    )->execute([':k' => $keywordId, ':d' => $date, ':p' => $position]);
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

function startServer(string $docroot): array
{
    $port = freePort();
    if ($port === null) {
        return [null, null];
    }
    $outFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_smoke_out_' . bin2hex(random_bytes(4)) . '.log';
    $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_smoke_err_' . bin2hex(random_bytes(4)) . '.log';
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port
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
    $GLOBALS['_smoke_logs'] = array_merge(
        $GLOBALS['_smoke_logs'] ?? [],
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
    foreach ($GLOBALS['_smoke_logs'] ?? [] as $file) {
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
    $GLOBALS['_smoke_logs'] = [];
}

function httpGet(string $url): array
{
    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        $line = $http_response_header[0];
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $body === false ? '' : $body];
}

function httpGetRetry(string $url): array
{
    for ($i = 0; $i < 20; $i++) {
        [$status, $body] = httpGet($url);
        if ($status !== 0) {
            return [$status, $body];
        }
        usleep(100000);
    }
    return [0, ''];
}

$docroot = __DIR__ . '/../public';

echo "## Smoke: seeded detail page over HTTP\n";
$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();
minirank_seed($db);

$firstId = (int) $db->query('SELECT id FROM keywords ORDER BY id LIMIT 1')->fetchColumn();
$newest = $db->query(
    'SELECT recorded_on FROM positions WHERE keyword_id = ' . (int) $firstId
    . ' ORDER BY recorded_on DESC LIMIT 1'
)->fetchColumn();
$oldest = $db->query(
    'SELECT recorded_on FROM positions WHERE keyword_id = ' . (int) $firstId
    . ' ORDER BY recorded_on ASC LIMIT 1'
)->fetchColumn();

$noHistoryId = insertKeyword($db, 'no history smoke');
$xssId = insertKeyword($db, '<script>alert("xss")</script>');
insertPosition($db, $xssId, $newest, 3);

$deletedId = (int) $db->query(
    'SELECT id FROM keywords ORDER BY id LIMIT 1 OFFSET 1'
)->fetchColumn();
$db->prepare('DELETE FROM keywords WHERE id = :id')->execute([':id' => $deletedId]);

$db = null;
minirank_db_close();
gc_collect_cycles();

[$proc, $port] = startServer($docroot);
report(is_resource($proc) && $port !== null, 'built-in server starts and binds a chosen port');
if (!is_resource($proc) || $port === null) {
    echo "\n1 passed, 1 failed\n";
    cleanup($scratch);
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;

[$status, $body] = httpGetRetry($base . '/');
report($status === 200, 'list page responds 200');
report(
    strpos($body, 'keyword.php?id=' . $firstId) !== false,
    'list page links the seeded keyword to its detail page'
);

[$status, $body] = httpGetRetry($base . '/keyword.php?id=' . $firstId);
report($status === 200, 'valid keyword id responds 200');
report(strpos($body, '<table class="history"') !== false, 'valid keyword renders a history table');
$tableStart = strpos($body, '<table class="history"');
$tableEnd = strpos($body, '</table>');
$table = $tableStart !== false && $tableEnd !== false && $tableEnd > $tableStart
    ? substr($body, $tableStart, $tableEnd - $tableStart)
    : '';
report(
    $table !== '' && strpos($table, $newest) < strpos($table, $oldest),
    'history table is newest first over HTTP'
);
report(substr_count($table, '<td class="position">') === 30, 'seeded keyword shows 30 position rows');

echo "\n## Smoke: chart on the detail page\n";
report(strpos($body, '<figure class="chart">') !== false, 'seeded detail page renders a chart figure');
report(strpos($body, '<svg class="chart-svg"') !== false, 'seeded detail page renders an SVG chart');
$chartStart = strpos($body, '<svg class="chart-svg"');
$chartEnd = strpos($body, '</svg>');
$chart = $chartStart !== false && $chartEnd !== false && $chartEnd > $chartStart
    ? substr($body, $chartStart, $chartEnd - $chartStart)
    : '';
report(
    strpos($chart, '>1</text>') !== false && strpos($chart, '>100</text>') !== false,
    'chart shows visible 1 and 100 axis labels'
);
report(substr_count($chart, '<line class="chart-grid"') === 2, 'chart shows gridlines at positions 1 and 100');
report(substr_count($chart, '<polyline') === 1, 'chart has a single polyline');
$chartPoints = [];
if (preg_match('/points="([^"]+)"/', $chart, $pm) === 1) {
    $chartPoints = explode(' ', $pm[1]);
}
report(count($chartPoints) === 30, 'chart polyline has one point per seeded day (30 points)');
report(substr_count($chart, '<circle class="chart-dot"') === 30, 'chart draws one dot per point (30 dots)');
report(strpos($chart, '<table') === false, 'chart markup contains no table');

[$status, $body] = httpGetRetry($base . '/keyword.php?id=' . $noHistoryId);
report($status === 200, 'keyword with no history responds 200');
report(
    strpos($body, 'No position history yet for this keyword.') !== false,
    'no-history keyword shows empty state message'
);
report(strpos($body, '<table') === false, 'no-history keyword renders no table');
report(strpos($body, '<svg') === false, 'no-history keyword renders no chart');

[$status, $body] = httpGetRetry($base . '/keyword.php?id=' . $xssId);
report($status === 200, 'hostile-phrase keyword responds 200');
report(strpos($body, '<script>alert') === false, 'raw script tag absent from detail page');
report(strpos($body, '&lt;script&gt;alert') !== false, 'hostile phrase is escaped in detail page');
report(substr_count($body, '<circle class="chart-dot"') === 1, 'single-position keyword renders exactly one dot');
report(strpos($body, '<polyline') === false, 'single-position keyword renders no polyline');

echo "\n## Smoke: safe 404s\n";
[$status, $body] = httpGetRetry($base . '/keyword.php');
report($status === 404, 'missing id responds 404');
report(strpos($body, 'Keyword not found.') !== false, '404 body explains keyword not found');
[$status, $body] = httpGetRetry($base . '/keyword.php?id=abc');
report($status === 404, 'non-numeric id responds 404');
[$status, $body] = httpGetRetry($base . '/keyword.php?id=-5');
report($status === 404, 'negative id responds 404');
[$status, $body] = httpGetRetry($base . '/keyword.php?id=0');
report($status === 404, 'zero id responds 404');
[$status, $body] = httpGetRetry($base . '/keyword.php?id=999999');
report($status === 404, 'unknown id responds 404');
[$status, $body] = httpGetRetry($base . '/keyword.php?id=' . $deletedId);
report($status === 404, 'genuinely deleted keyword id responds 404');
report(strpos($body, 'Keyword not found.') !== false, 'deleted keyword 404 shows the safe not-found page');
[$status, $body] = httpGetRetry($base . '/keyword.php?id[]=1');
report($status === 404, 'array id responds 404');

stopServer($proc);
$proc = null;

echo "\n## Smoke: generic 500 on unopenable database\n";
putenv('MINIRANK_DB_PATH=' . sys_get_temp_dir());
[$proc, $port] = startServer($docroot);
report(is_resource($proc) && $port !== null, 'second server starts against an unopenable database path');
if (!is_resource($proc) || $port === null) {
    echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
    cleanup($scratch);
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;

[$status, $body] = httpGetRetry($base . '/keyword.php?id=1');
report($status === 500, 'detail page responds 500 when the database cannot open');
report(
    strpos($body, 'SQLSTATE') === false
        && strpos($body, 'PDOException') === false
        && strpos($body, 'unable to open') === false
        && strpos($body, sys_get_temp_dir()) === false,
    '500 body is generic with no exception or path details'
);
[$status, $body] = httpGetRetry($base . '/');
report($status === 500, 'list page also responds 500 on unopenable database');

stopServer($proc);
$proc = null;
putenv('MINIRANK_DB_PATH');
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}