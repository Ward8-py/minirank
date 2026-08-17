<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/keyword.php';

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
        . 'minirank_keyword_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();

echo "## Detail: id parsing\n";
report(minirank_parse_id('5') === 5, 'numeric string "5" parses to int 5');
report(minirank_parse_id('1') === 1, 'numeric string "1" parses to int 1');
report(minirank_parse_id(null) === null, 'missing value (null) rejected');
report(minirank_parse_id('') === null, 'empty string rejected');
report(minirank_parse_id('abc') === null, 'non-numeric string rejected');
report(minirank_parse_id('-5') === null, 'negative id rejected');
report(minirank_parse_id('0') === null, 'zero id rejected');
report(minirank_parse_id('5.5') === null, 'float string rejected');
report(minirank_parse_id('1e3') === null, 'scientific notation rejected');
report(minirank_parse_id([]) === null, 'array value rejected');
report(minirank_parse_id(['5']) === null, 'array containing digits rejected');
report(minirank_parse_id(5) === null, 'non-string int rejected (raw GET values are strings)');

echo "\n## Detail: find keyword\n";
$kw = insertKeyword($db, 'seo tools');
$found = minirank_find_keyword($db, $kw);
report(
    is_array($found)
        && $found['id'] === $kw
        && $found['phrase'] === 'seo tools'
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $found['created_at']) === 1,
    'existing keyword returns id, phrase, YYYY-MM-DD created_at'
);
report(minirank_find_keyword($db, 999999) === null, 'never-existing (unknown) id returns null');

echo "\n## Detail: deleted keyword vs unknown id\n";
$doomed = insertKeyword($db, 'doomed keyword');
insertPosition($db, $doomed, '2026-08-10', 3);
insertPosition($db, $doomed, '2026-08-17', 7);
report(is_array(minirank_find_keyword($db, $doomed)), 'setup: doomed keyword exists with history');
$db->prepare('DELETE FROM keywords WHERE id = :id')->execute([':id' => $doomed]);
report(minirank_find_keyword($db, $doomed) === null, 'deleted keyword id returns null from find_keyword');
report(minirank_position_history($db, $doomed) === [], 'deleted keyword id returns no position history (cascade)');
report(minirank_position_history($db, 999999) === [], 'never-existing id also returns no position history');

echo "\n## Detail: position history ordering\n";
$kw = insertKeyword($db, 'history order');
insertPosition($db, $kw, '2026-08-10', 20);
insertPosition($db, $kw, '2026-08-13', 12);
insertPosition($db, $kw, '2026-08-17', 5);
$history = minirank_position_history($db, $kw);
report(count($history) === 3, 'history returns every recorded position');
report(
    $history[0]['recorded_on'] === '2026-08-17'
        && $history[1]['recorded_on'] === '2026-08-13'
        && $history[2]['recorded_on'] === '2026-08-10',
    'history is ordered newest first by date'
);
report(
    $history[0]['position'] === 5
        && $history[1]['position'] === 12
        && $history[2]['position'] === 20,
    'positions pair correctly with their dates and are ints'
);

echo "\n## Detail: empty history\n";
$kw = insertKeyword($db, 'no history yet');
report(minirank_position_history($db, $kw) === [], 'keyword with no positions returns empty history');

echo "\n## Detail: render history\n";
$html = minirank_render_history([]);
report(strpos($html, 'No position history yet for this keyword.') !== false, 'empty history renders empty-state message');
report(strpos($html, '<table') === false, 'empty history renders no table');

$html = minirank_render_history($history);
report(strpos($html, '<table class="history"') !== false, 'non-empty history renders a history table');
report(strpos($html, '2026-08-17') !== false && strpos($html, '2026-08-10') !== false, 'table shows every date');
report(strpos($html, '>5<') !== false && strpos($html, '>20<') !== false, 'table shows every position');
report(
    strpos($html, '2026-08-17') < strpos($html, '2026-08-10'),
    'rendered table lists newest date before older dates'
);

$evil = minirank_render_history([
    ['recorded_on' => '<script>alert("xss")</script>', 'position' => 3],
]);
report(strpos($evil, '<script>alert') === false, 'raw script tag absent from rendered history row');
report(strpos($evil, '&lt;script&gt;alert') !== false, 'history row values are escaped');

$db = null;
$history = null;
$html = null;
$evil = null;
$found = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}