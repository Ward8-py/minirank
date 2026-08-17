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
        . 'minirank_edit_delete_smoke_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function startServer(string $docroot, array $iniArgs = []): array
{
    $port = freePort();
    if ($port === null) {
        return [null, null];
    }
    $outFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_edit_delete_smoke_out_' . bin2hex(random_bytes(4)) . '.log';
    $errFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_edit_delete_smoke_err_' . bin2hex(random_bytes(4)) . '.log';
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
    $GLOBALS['_edit_delete_smoke_logs'] = array_merge(
        $GLOBALS['_edit_delete_smoke_logs'] ?? [],
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
    foreach ($GLOBALS['_edit_delete_smoke_logs'] ?? [] as $file) {
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
    $GLOBALS['_edit_delete_smoke_logs'] = [];
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

function dbPositionCount(string $path, int $keywordId): int
{
    $pdo = dbPdo($path);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM positions WHERE keyword_id = :k');
    $stmt->execute([':k' => $keywordId]);
    $count = (int) $stmt->fetchColumn();
    $pdo = null;
    return $count;
}

function dbOrphanCount(string $path): int
{
    $pdo = dbPdo($path);
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM positions
         WHERE keyword_id NOT IN (SELECT id FROM keywords)'
    )->fetchColumn();
    $pdo = null;
    return $count;
}

function dbPhrase(string $path, int $keywordId): ?string
{
    $pdo = dbPdo($path);
    $stmt = $pdo->prepare('SELECT phrase FROM keywords WHERE id = :id');
    $stmt->execute([':id' => $keywordId]);
    $value = $stmt->fetchColumn();
    $pdo = null;
    return $value === false ? null : (string) $value;
}

function dbKeywordExists(string $path, int $keywordId): bool
{
    $pdo = dbPdo($path);
    $stmt = $pdo->prepare('SELECT 1 FROM keywords WHERE id = :id');
    $stmt->execute([':id' => $keywordId]);
    $exists = $stmt->fetchColumn() !== false;
    $pdo = null;
    return $exists;
}

$docroot = __DIR__ . '/../public';
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'minirank_sessions_' . bin2hex(random_bytes(4));
mkdir($sessionDir, 0700, true);

$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();
minirank_seed($db);

$firstId = (int) $db->query('SELECT id FROM keywords ORDER BY id LIMIT 1')->fetchColumn();
$deletedId = (int) $db->query(
    'SELECT id FROM keywords ORDER BY id LIMIT 1 OFFSET 1'
)->fetchColumn();
$db->prepare('DELETE FROM keywords WHERE id = :id')->execute([':id' => $deletedId]);

$targetId = insertKeyword($db, 'delete target');
insertPosition($db, $targetId, '2026-08-10', 20);
insertPosition($db, $targetId, '2026-08-13', 12);
insertPosition($db, $targetId, '2026-08-17', 9);

$survivorId = insertKeyword($db, 'survivor kw');
insertPosition($db, $survivorId, '2026-08-10', 5);
insertPosition($db, $survivorId, '2026-08-17', 3);

$hostileId = insertKeyword($db, '<script>alert("xss")</script>');
insertPosition($db, $hostileId, '2026-08-17', 2);

$caseId = insertKeyword($db, 'case me');

$db = null;
minirank_db_close();
gc_collect_cycles();

echo "## Smoke: edit page over HTTP\n";
[$proc, $port] = startServer($docroot, ['session.save_path=' . $sessionDir]);
report(is_resource($proc) && $port !== null, 'built-in server starts and binds a chosen port');
if (!is_resource($proc) || $port === null) {
    echo "\n1 passed, 1 failed\n";
    cleanup($scratch);
    removeDirTree($sessionDir);
    exit(1);
}
$base = 'http://127.0.0.1:' . $port;

[$status, $headers, $body] = httpGetRetry($base . '/keyword-edit.php?id=' . $firstId);
report($status === 200, 'edit page responds 200 for a valid id');
report(
    strpos($body, 'action="actions/keyword-update.php"') !== false,
    'edit form posts to the dedicated update endpoint'
);
report(strpos($body, 'name="id" value="' . $firstId . '"') !== false, 'edit form embeds the keyword id');
report(strpos($body, 'name="csrf_token"') !== false, 'edit form embeds a csrf token field');
$cookie = sessionCookieFrom($headers);
$token = extractToken($body);
report($cookie !== '' && $token !== '', 'session cookie and csrf token extracted');
[$status, , $body] = httpGetRetry($base . '/keyword-edit.php?id=' . $hostileId, $cookie);
report($status === 200, 'edit page responds 200 for a hostile-phrase keyword');
report(strpos($body, '<script>alert') === false, 'raw script tag absent from edit page');
report(strpos($body, '&lt;script&gt;alert') !== false, 'hostile phrase escaped in the edit form value');

echo "\n## Smoke: edit page safe 404s\n";
foreach (['', '?id=abc', '?id=-5', '?id=0', '?id=999999', '?id[]=1', '?id=' . $deletedId] as $suffix) {
    [$status, , $body] = httpGetRetry($base . '/keyword-edit.php' . $suffix, $cookie);
    report($status === 404, "edit page 404 for id$suffix");
    report(strpos($body, 'Keyword not found.') !== false, "404 body for id$suffix is the safe not-found page");
}

echo "\n## Smoke: update 405 and 403 without mutation\n";
$before = dbPhrase($scratch, $caseId);
[$status, , $body] = httpGet($base . '/actions/keyword-update.php?id=' . $caseId, $cookie);
report($status === 405, 'GET on the update endpoint returns 405');
report(strpos($body, 'href="../index.php"') !== false, '405 page links back to the list page');
report(strpos($body, 'href="index.php"') === false, '405 page has no relative link that would 404');
[$status, , ] = httpPost(
    $base . '/actions/keyword-update.php',
    ['id' => $caseId, 'phrase' => 'csrf blocked']
);
report($status === 403, 'update POST without csrf token returns 403');
[$status, , ] = httpPost(
    $base . '/actions/keyword-update.php',
    ['csrf_token' => 'deadbeefdeadbeef', 'id' => $caseId, 'phrase' => 'csrf blocked'],
    $cookie
);
report($status === 403, 'update POST with wrong csrf token returns 403');
report(dbPhrase($scratch, $caseId) === $before, 'failed csrf attempts change no phrase');
report(dbKeywordCount($scratch) === 9, 'failed csrf attempts add no keywords');

echo "\n## Smoke: update invalid and unknown ids return 404 without mutation\n";
$before = dbPhrase($scratch, $caseId);
$beforeCount = dbKeywordCount($scratch);
$updateBadIds = [
    'missing id' => ['phrase' => 'ok'],
    'non-numeric id' => ['id' => 'abc', 'phrase' => 'ok'],
    'negative id' => ['id' => '-5', 'phrase' => 'ok'],
    'zero id' => ['id' => '0', 'phrase' => 'ok'],
    'unknown id' => ['id' => '999999', 'phrase' => 'ok'],
    'deleted id' => ['id' => $deletedId, 'phrase' => 'ok'],
    'array id' => ['id' => ['1'], 'phrase' => 'ok'],
];
foreach ($updateBadIds as $label => $fields) {
    [$status, , $body] = httpPost(
        $base . '/actions/keyword-update.php',
        ['csrf_token' => $token] + $fields,
        $cookie
    );
    report($status === 404, "update POST with $label returns 404");
    report(strpos($body, 'Keyword not found.') !== false, "404 body for $label is the safe not-found page");
}
report(dbPhrase($scratch, $caseId) === $before, '404 update attempts change no phrase');
report(dbKeywordCount($scratch) === $beforeCount, '404 update attempts add no keywords');

echo "\n## Smoke: valid update with 303 redirect\n";
[$status, $headers, ] = httpPost(
    $base . '/actions/keyword-update.php',
    ['csrf_token' => $token, 'id' => $caseId, 'phrase' => 'CASE ME'],
    $cookie
);
report($status === 303, 'keeping the same phrase in a different case returns 303');
report(
    strpos(locationHeader($headers), 'keyword.php?id=' . $caseId) !== false,
    'case-change update redirects to the keyword detail page'
);
report(dbPhrase($scratch, $caseId) === 'CASE ME', 'case-change update stored the new case');
[$status, , $body] = httpGetRetry($base . '/keyword.php?id=' . $caseId, $cookie);
report($status === 200, 'detail page responds 200 after update');
report(strpos($body, 'CASE ME') !== false, 'updated phrase appears on the detail page');
report(strpos($body, 'Keyword updated.') !== false, 'success flash shown on the detail page');

echo "\n## Smoke: blank and oversized update rejected\n";
[$status, , ] = httpPost(
    $base . '/actions/keyword-update.php',
    ['csrf_token' => $token, 'id' => $caseId, 'phrase' => '   '],
    $cookie
);
report($status === 303, 'blank update POST returns 303');
report(dbPhrase($scratch, $caseId) === 'CASE ME', 'blank update changes no phrase');
[$status, , $body] = httpGetRetry($base . '/keyword-edit.php?id=' . $caseId, $cookie);
report(strpos($body, 'Keyword cannot be blank.') !== false, 'blank error flash shown on the edit page');

[$status, , ] = httpPost(
    $base . '/actions/keyword-update.php',
    ['csrf_token' => $token, 'id' => $caseId, 'phrase' => str_repeat('o', 121)],
    $cookie
);
report($status === 303, 'oversized update POST returns 303');
report(dbPhrase($scratch, $caseId) === 'CASE ME', 'oversized update changes no phrase');
[$status, , $body] = httpGetRetry($base . '/keyword-edit.php?id=' . $caseId, $cookie);
report(
    strpos($body, '120 characters or fewer') !== false,
    'oversized error flash shown on the edit page'
);

echo "\n## Smoke: case-insensitive duplicate update rejected\n";
[$status, , ] = httpPost(
    $base . '/actions/keyword-update.php',
    ['csrf_token' => $token, 'id' => $caseId, 'phrase' => 'SEO TOOLS'],
    $cookie
);
report($status === 303, 'duplicate update POST returns 303');
report(dbPhrase($scratch, $caseId) === 'CASE ME', 'duplicate update changes no phrase');
[$status, , $body] = httpGetRetry($base . '/keyword-edit.php?id=' . $caseId, $cookie);
report(strpos($body, 'already exists') !== false, 'duplicate error flash shown on the edit page');

echo "\n## Smoke: hostile phrase stored and escaped on edit page\n";
$hostileNew = '<b>bold</b>';
[$status, , ] = httpPost(
    $base . '/actions/keyword-update.php',
    ['csrf_token' => $token, 'id' => $hostileId, 'phrase' => $hostileNew],
    $cookie
);
report($status === 303, 'hostile-phrase update POST returns 303');
report(dbPhrase($scratch, $hostileId) === $hostileNew, 'hostile phrase stored verbatim on update');
[$status, , $body] = httpGetRetry($base . '/keyword-edit.php?id=' . $hostileId, $cookie);
report($status === 200, 'edit page responds 200 with hostile keyword present');
report(strpos($body, '<b>bold</b>') === false, 'raw hostile tag absent from the rendered edit page');
report(strpos($body, '&lt;b&gt;bold&lt;/b&gt;') !== false, 'hostile phrase escaped in the edit form value');

echo "\n## Smoke: delete 405 and 403 without mutation\n";
$beforeCount = dbKeywordCount($scratch);
[$status, , $body] = httpGet($base . '/actions/keyword-delete.php?id=' . $targetId, $cookie);
report($status === 405, 'GET on the delete endpoint returns 405');
report(strpos($body, 'href="../index.php"') !== false, '405 page links back to the list page');
report(strpos($body, 'href="index.php"') === false, '405 page has no relative link that would 404');
[$status, , ] = httpPost($base . '/actions/keyword-delete.php', ['id' => $targetId]);
report($status === 403, 'delete POST without csrf token returns 403');
[$status, , ] = httpPost(
    $base . '/actions/keyword-delete.php',
    ['csrf_token' => 'deadbeefdeadbeef', 'id' => $targetId],
    $cookie
);
report($status === 403, 'delete POST with wrong csrf token returns 403');
report(dbKeywordCount($scratch) === $beforeCount, 'failed csrf deletes remove no keywords');
report(dbPositionCount($scratch, $targetId) === 3, 'failed csrf deletes keep the target history');
report(dbOrphanCount($scratch) === 0, 'failed csrf deletes leave no orphans');

echo "\n## Smoke: delete invalid and unknown ids return 404 without mutation\n";
$beforeCount = dbKeywordCount($scratch);
$deleteBadIds = [
    'missing id' => [],
    'non-numeric id' => ['id' => 'abc'],
    'negative id' => ['id' => '-5'],
    'zero id' => ['id' => '0'],
    'unknown id' => ['id' => '999999'],
    'deleted id' => ['id' => $deletedId],
    'array id' => ['id' => ['1']],
];
foreach ($deleteBadIds as $label => $fields) {
    [$status, , $body] = httpPost(
        $base . '/actions/keyword-delete.php',
        ['csrf_token' => $token] + $fields,
        $cookie
    );
    report($status === 404, "delete POST with $label returns 404");
    report(strpos($body, 'Keyword not found.') !== false, "404 body for $label is the safe not-found page");
}
report(dbKeywordCount($scratch) === $beforeCount, '404 delete attempts remove no keywords');
report(dbPositionCount($scratch, $targetId) === 3, '404 delete attempts keep the target history');
report(dbOrphanCount($scratch) === 0, '404 delete attempts leave no orphans');

echo "\n## Smoke: valid delete cascades history and leaves others intact\n";
[$status, $headers, ] = httpPost(
    $base . '/actions/keyword-delete.php',
    ['csrf_token' => $token, 'id' => $targetId],
    $cookie
);
report($status === 303, 'valid delete POST returns 303');
report(strpos(locationHeader($headers), 'index.php') !== false, 'delete redirects back to the list page');
report(dbKeywordExists($scratch, $targetId) === false, 'deleted keyword row is gone');
report(dbKeywordCount($scratch) === 8, 'exactly one keyword was removed');
report(dbPositionCount($scratch, $targetId) === 0, 'deleted keyword has no remaining positions (cascade)');
report(dbPositionCount($scratch, $survivorId) === 2, 'survivor positions are untouched by the delete');
report(dbPositionCount($scratch, $firstId) === 30, 'seeded keyword history is untouched by the delete');
report(dbOrphanCount($scratch) === 0, 'delete leaves no orphan position rows');
[$status, , $body] = httpGetRetry($base . '/', $cookie);
report($status === 200, 'list page responds 200 after delete');
report(strpos($body, 'delete target') === false, 'deleted keyword is gone from the list');
report(strpos($body, 'survivor kw') !== false, 'survivor keyword still appears in the list');
report(strpos($body, 'Keyword deleted.') !== false, 'success flash shown on the list page');
[$status, , $body] = httpGetRetry($base . '/keyword.php?id=' . $targetId, $cookie);
report($status === 404, 'deleted keyword id returns 404 on the detail page');
[$status, , $body] = httpGetRetry($base . '/keyword-edit.php?id=' . $targetId, $cookie);
report($status === 404, 'deleted keyword id returns 404 on the edit page');

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

foreach (['GET' => '/keyword-edit.php?id=' . $firstId, 'POST update' => null, 'POST delete' => null] as $kind => $url) {
    if ($kind === 'GET') {
        [$status, , $body] = httpGetRetry($base2 . $url, $cookie);
    } else {
        $action = $kind === 'POST update' ? 'keyword-update.php' : 'keyword-delete.php';
        [$status, , $body] = httpPost(
            $base2 . '/actions/' . $action,
            ['csrf_token' => $token, 'id' => $firstId, 'phrase' => 'unopenable attempt'],
            $cookie
        );
    }
    report($status === 500, "$kind endpoint responds 500 when the database cannot open");
    report(
        strpos($body, 'SQLSTATE') === false
            && strpos($body, 'PDOException') === false
            && strpos($body, 'unable to open') === false
            && strpos($body, sys_get_temp_dir()) === false,
        "$kind 500 body is generic with no exception or path details"
    );
}

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