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
        . 'minirank_export_smoke_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        . 'minirank_export_smoke_out_' . bin2hex(random_bytes(4)) . '.log';
    $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_export_smoke_err_' . bin2hex(random_bytes(4)) . '.log';
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
    $headers = is_array($http_response_header) ? $http_response_header : [];
    $status = 0;
    if ($headers !== []) {
        $line = $headers[0];
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $body === false ? '' : $body, $headers];
}

function httpGetRetry(string $url): array
{
    for ($i = 0; $i < 20; $i++) {
        [$status, $body, $headers] = httpGet($url);
        if ($status !== 0) {
            return [$status, $body, $headers];
        }
        usleep(100000);
    }
    return [0, '', []];
}

function hasHeader(array $headers, string $needle): bool
{
    foreach ($headers as $line) {
        if (stripos($line, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function headerLine(array $headers, string $prefix): string
{
    foreach ($headers as $line) {
        if (stripos($line, $prefix) === 0) {
            return $line;
        }
    }
    return '';
}

$docroot = __DIR__ . '/../public';

echo "## Smoke: CSV export over HTTP\n";
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

$noHistoryId = insertKeyword($db, 'no history export');
$hostileId = insertKeyword($db, '=1+1');
insertPosition($db, $hostileId, $newest, 3);

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

[$status, $body] = httpGetRetry($base . '/keyword.php?id=' . $firstId);
report($status === 200, 'detail page responds 200');
report(
    strpos($body, 'Export CSV') !== false
        && strpos($body, 'export.php?id=' . $firstId) !== false,
    'detail page links the Export CSV download'
);

[$status, $body, $headers] = httpGetRetry($base . '/export.php?id=' . $firstId);
report($status === 200, 'seeded keyword export responds 200');
report(hasHeader($headers, 'Content-Type: text/csv'), 'export declares a text/csv content type');
$cd = headerLine($headers, 'Content-Disposition');
report(
    strpos($cd, 'attachment; filename="') !== false && substr($cd, -1) === '"',
    'export declares an attachment download with a quoted filename'
);
report(
    strpos($cd, 'filename="' . $firstId . '-history.csv"') !== false,
    'filename uses the fixed numeric <id>-history.csv'
);
report(
    strpos($cd, "\r") === false && strpos($cd, "\n") === false,
    'Content-Disposition carries no embedded newline'
);
report(hasHeader($headers, 'X-Content-Type-Options: nosniff'), 'export sends nosniff');
$lines = explode("\n", $body);
$dataLines = count($lines) >= 2 ? array_slice($lines, 1, -1) : [];
report($lines[0] === 'Keyword,Date,Position', 'export body starts with the CSV header row');
report(count($dataLines) === 30, 'seeded keyword exports one row per history entry (30 rows)');
report(
    strpos($dataLines[0], $newest) !== false && strpos($dataLines[29], $oldest) !== false,
    'exported rows are newest first like the history table'
);

[$status, $body, $headers] = httpGetRetry($base . '/export.php?id=' . $noHistoryId);
report($status === 200, 'no-history keyword export responds 200');
report(
    $body === "Keyword,Date,Position\n",
    'no-history keyword exports the header row only'
);

[$status, $body, $headers] = httpGetRetry($base . '/export.php?id=' . $hostileId);
report($status === 200, 'hostile-formula keyword export responds 200');
report(strpos($body, "'=1+1") !== false, 'formula-leading phrase is neutralized in the export');
report(strpos($body, "\n=1+1,") === false, 'no exported row begins with an unguarded formula cell');
$cd = headerLine($headers, 'Content-Disposition');
report(
    strpos($cd, 'filename="' . $hostileId . '-history.csv"') !== false,
    'export uses the fixed numeric filename regardless of the phrase'
);
report(
    strpos($cd, "\r") === false && strpos($cd, "\n") === false,
    'hostile filename carries no CR or LF in Content-Disposition'
);

echo "\n## Smoke: safe 404s on export\n";
[$status, $body] = httpGetRetry($base . '/export.php');
report($status === 404, 'missing id responds 404');
report(strpos($body, 'Keyword not found.') !== false, '404 body explains keyword not found');
[$status, $body] = httpGetRetry($base . '/export.php?id=abc');
report($status === 404, 'non-numeric id responds 404');
[$status, $body] = httpGetRetry($base . '/export.php?id=-5');
report($status === 404, 'negative id responds 404');
[$status, $body] = httpGetRetry($base . '/export.php?id=0');
report($status === 404, 'zero id responds 404');
[$status, $body] = httpGetRetry($base . '/export.php?id=999999');
report($status === 404, 'unknown id responds 404');
[$status, $body] = httpGetRetry($base . '/export.php?id=' . $deletedId);
report($status === 404, 'genuinely deleted keyword id responds 404');
report(strpos($body, 'Keyword not found.') !== false, 'deleted keyword 404 shows the safe not-found page');
[$status, $body] = httpGetRetry($base . '/export.php?id[]=1');
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

[$status, $body] = httpGetRetry($base . '/export.php?id=1');
report($status === 500, 'export responds 500 when the database cannot open');
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

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}