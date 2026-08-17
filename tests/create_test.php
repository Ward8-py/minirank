<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
session_save_path(sys_get_temp_dir());
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/keyword.php';
require_once __DIR__ . '/../src/list.php';
require_once __DIR__ . '/../src/session.php';

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
        . 'minirank_create_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function keywordCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM keywords')->fetchColumn();
}

$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();

echo "## Create: phrase validation\n";
$v = minirank_validate_phrase('seo tools');
report($v['ok'] === true && $v['phrase'] === 'seo tools', 'valid phrase accepted unchanged');
$v = minirank_validate_phrase('  seo tools  ');
report($v['ok'] === true && $v['phrase'] === 'seo tools', 'leading/trailing spaces trimmed before accept');
$v = minirank_validate_phrase('');
report($v['ok'] === false, 'empty phrase rejected');
$v = minirank_validate_phrase('   ');
report($v['ok'] === false, 'whitespace-only phrase rejected');
$v = minirank_validate_phrase("\t\n ");
report($v['ok'] === false, 'tab/newline whitespace-only phrase rejected');
$v = minirank_validate_phrase(str_repeat('a', 121));
report(
    $v['ok'] === false && is_string($v['error']) && strpos($v['error'], '120') !== false,
    '121-char phrase rejected as oversized'
);
$v = minirank_validate_phrase(str_repeat('b', 120));
report($v['ok'] === true, '120-char phrase accepted at the boundary');
$v = minirank_validate_phrase('a');
report($v['ok'] === true, 'single-char phrase accepted');

echo "\n## Create: keyword insert\n";
$r = minirank_create_keyword($db, 'fresh keyword');
report($r['ok'] === true && is_int($r['id']) && $r['id'] > 0, 'create returns ok with a new positive id');
$r = minirank_create_keyword($db, '  padded phrase  ');
report($r['ok'] === true, 'create accepts a padded phrase');
$stmt = $db->prepare('SELECT phrase FROM keywords WHERE id = :id');
$stmt->execute([':id' => $r['id']]);
report($stmt->fetchColumn() === 'padded phrase', 'stored phrase is trimmed');
report(keywordCount($db) === 2, 'two keywords exist after two creates');

echo "\n## Create: duplicates rejected\n";
$before = keywordCount($db);
$r = minirank_create_keyword($db, 'Fresh Keyword');
report(
    $r['ok'] === false && is_string($r['error']) && strpos($r['error'], 'already exists') !== false,
    'case-insensitive duplicate rejected with friendly error'
);
$r = minirank_create_keyword($db, '  PADDED PHRASE  ');
report($r['ok'] === false, 'trimmed duplicate (padded) rejected');
report(keywordCount($db) === $before, 'duplicate attempts add no rows');
report(minirank_keyword_exists($db, 'fresh keyword') === true, 'keyword_exists true for existing phrase');
report(minirank_keyword_exists($db, 'never seen phrase') === false, 'keyword_exists false for missing phrase');

echo "\n## Create: blank and oversized do not insert\n";
$before = keywordCount($db);
$r = minirank_create_keyword($db, '   ');
report($r['ok'] === false, 'blank phrase create rejected');
$r = minirank_create_keyword($db, str_repeat('x', 121));
report($r['ok'] === false, 'oversized phrase create rejected');
report(keywordCount($db) === $before, 'rejected creates add no rows');

echo "\n## Create: add form rendering\n";
$html = minirank_render_add_form('abc123');
report(
    strpos($html, 'method="post"') !== false
        && strpos($html, 'action="actions/keyword-create.php"') !== false,
    'add form posts directly to the dedicated create endpoint'
);
report(
    strpos($html, 'name="csrf_token" value="abc123"') !== false,
    'add form embeds the csrf token'
);
report(
    strpos($html, 'name="phrase"') !== false && strpos($html, 'maxlength="120"') !== false,
    'add form has a phrase field capped at 120 chars'
);
$html = minirank_render_add_form('"><script>alert(1)</script>');
report(strpos($html, '<script>alert') === false, 'raw script tag absent from rendered add form');
report(strpos($html, '&quot;&gt;&lt;script&gt;alert') !== false, 'hostile csrf token escaped in add form');

echo "\n## Create: csrf token lifecycle\n";
$token = minirank_csrf_token();
report(is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1, 'csrf token is a 64-char hex string');
report(minirank_csrf_token() === $token, 'csrf token is stable across calls in the same session');
report(minirank_csrf_verify($token) === true, 'verify accepts the issued token');
report(minirank_csrf_verify('wrong-token') === false, 'verify rejects a wrong token');
report(minirank_csrf_verify('') === false, 'verify rejects an empty token');
$field = minirank_csrf_field();
report(
    strpos($field, 'name="csrf_token"') !== false && strpos($field, $token) !== false,
    'csrf_field renders a hidden input carrying the token'
);

echo "\n## Create: flash messages\n";
minirank_flash_set('success', 'Keyword added.');
$flash = minirank_flash_pull();
report($flash === ['type' => 'success', 'message' => 'Keyword added.'], 'flash_set stores one-shot message');
report(minirank_flash_pull() === [], 'flash is cleared after one pull');
$html = minirank_flash_render(['type' => 'success', 'message' => 'Keyword added.']);
report(
    strpos($html, 'alert-success') !== false && strpos($html, 'Keyword added.') !== false,
    'success flash renders an alert-success message'
);
$html = minirank_flash_render(['type' => 'error', 'message' => 'Keyword cannot be blank.']);
report(
    strpos($html, 'alert-error') !== false && strpos($html, 'Keyword cannot be blank.') !== false,
    'error flash renders an alert-error message'
);
$html = minirank_flash_render(['type' => 'error', 'message' => '<script>alert(1)</script>']);
report(strpos($html, '<script>alert') === false, 'raw script tag absent from rendered flash');
report(strpos($html, '&lt;script&gt;alert') !== false, 'flash message content escaped');
report(minirank_flash_render([]) === '', 'empty flash renders nothing');
report(minirank_flash_render(['type' => 'error']) === '', 'flash missing message renders nothing');

$db = null;
$r = null;
$v = null;
$html = null;
$flash = null;
$token = null;
$field = null;
$stmt = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}