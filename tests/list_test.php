<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/list.php';

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
        . 'minirank_list_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function byPhrase(PDO $pdo, string $query, ?string $today): array
{
    $map = [];
    foreach (minirank_search_keywords($pdo, $query, $today) as $row) {
        $map[$row['phrase']] = $row;
    }
    return $map;
}

$today = '2026-08-17';
$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();

echo "## List: blank and whitespace search\n";
$kw = insertKeyword($db, 'seo tools');
insertPosition($db, $kw, '2026-08-10', 20);
insertPosition($db, $kw, '2026-08-17', 10);
$kw = insertKeyword($db, 'keyword rank checker');
insertPosition($db, $kw, '2026-08-10', 10);
insertPosition($db, $kw, '2026-08-17', 20);
$kw = insertKeyword($db, 'free rank tracker');
insertPosition($db, $kw, '2026-08-10', 15);
insertPosition($db, $kw, '2026-08-17', 15);
$kw = insertKeyword($db, 'brand new keyword');

$all = minirank_search_keywords($db, '', $today);
report(count($all) === 4, 'blank query returns every keyword');
$phrases = array_column($all, 'phrase');
report(in_array('seo tools', $phrases, true), 'blank query includes a keyword with positions');
report(in_array('brand new keyword', $phrases, true), 'blank query includes a keyword with no positions');
$ws = minirank_search_keywords($db, '   ', $today);
report(count($ws) === 4, 'whitespace-only query returns every keyword');

echo "\n## List: normal and case-insensitive search\n";
$rows = minirank_search_keywords($db, 'seo', $today);
report(count($rows) === 1 && $rows[0]['phrase'] === 'seo tools', 'substring search finds "seo tools"');
$rows = minirank_search_keywords($db, 'SEO TOOLS', $today);
report(count($rows) === 1 && $rows[0]['phrase'] === 'seo tools', 'case-insensitive search matches different casing');
$rows = minirank_search_keywords($db, 'rank', $today);
report(count($rows) === 2, 'substring "rank" matches two keywords');
$rows = minirank_search_keywords($db, 'nonexistent phrase', $today);
report($rows === [], 'no-match search returns no rows');

echo "\n## List: LIKE wildcards are literal\n";
$kw = insertKeyword($db, '50% off tool');
$kw = insertKeyword($db, 'best_of_tool');
$kw = insertKeyword($db, 'win\back');

$rows = minirank_search_keywords($db, '50%', $today);
report(count($rows) === 1 && $rows[0]['phrase'] === '50% off tool', 'percent sign in query matches literally');
$rows = minirank_search_keywords($db, '%', $today);
report(count($rows) === 1 && $rows[0]['phrase'] === '50% off tool', 'searching for "%" matches only the literal "%" phrase');
$rows = minirank_search_keywords($db, '_', $today);
report(count($rows) === 1 && $rows[0]['phrase'] === 'best_of_tool', 'underscore in query matches literally');
$rows = minirank_search_keywords($db, 'best_of', $today);
report(count($rows) === 1 && $rows[0]['phrase'] === 'best_of_tool', 'underscore is not a single-char wildcard');
$rows = minirank_search_keywords($db, 'win\back', $today);
report(count($rows) === 1 && $rows[0]['phrase'] === 'win\back', 'backslash in query matches literally');

echo "\n## List: SQL injection input is inert\n";
$rows = minirank_search_keywords($db, "' OR 1=1 --", $today);
report($rows === [], 'quoted injection returns no rows');
$rows = minirank_search_keywords($db, "'; DROP TABLE keywords; --", $today);
report($rows === [], 'DROP-style injection returns no rows');
$rows = minirank_search_keywords($db, '%' . str_repeat('_', 50), $today);
report($rows === [], 'wildcard flood does not match everything');
report(
    (int) $db->query('SELECT COUNT(*) FROM keywords')->fetchColumn() === 7,
    'keywords table intact after injection inputs'
);

echo "\n## List: current position and trend\n";
$map = byPhrase($db, '', $today);
report($map['seo tools']['current_position'] === 10, 'current position is the newest recorded position');
report($map['seo tools']['baseline_position'] === 20, 'baseline is the newest position on or before today-7');
report(
    $map['seo tools']['direction'] === MINIRANK_TREND_IMPROVED
        && $map['seo tools']['not_enough_history'] === false,
    'current below baseline displays improved'
);
report($map['keyword rank checker']['direction'] === MINIRANK_TREND_DECLINED, 'current above baseline displays declined');
report($map['free rank tracker']['direction'] === MINIRANK_TREND_STABLE, 'equal positions display stable');

echo "\n## List: missing history\n";
report($map['brand new keyword']['current_position'] === null, 'keyword with no positions has null current');
report($map['brand new keyword']['baseline_position'] === null, 'keyword with no positions has null baseline');
report(
    $map['brand new keyword']['direction'] === MINIRANK_TREND_STABLE
        && $map['brand new keyword']['not_enough_history'] === true,
    'no positions at all -> stable with not enough history'
);

$kw = insertKeyword($db, 'recent only');
insertPosition($db, $kw, '2026-08-17', 5);
$rows = minirank_search_keywords($db, 'recent', $today);
report(
    $rows[0]['current_position'] === 5
        && $rows[0]['baseline_position'] === null
        && $rows[0]['not_enough_history'] === true,
    'current present but no baseline -> not enough history'
);

echo "\n## List: rendering escapes dynamic values\n";
$kw = insertKeyword($db, '<script>alert("xss")</script>');
insertPosition($db, $kw, '2026-08-17', 3);
$rows = minirank_search_keywords($db, 'script', $today);
$html = minirank_render_list($rows);
report(strpos($html, '<script>alert') === false, 'raw script tag absent from rendered list');
report(strpos($html, '&lt;script&gt;alert') !== false, 'script content escaped in rendered list');

$searchHtml = minirank_render_search('"><script>alert(1)</script>');
report(strpos($searchHtml, '<script>alert') === false, 'raw script tag absent from rendered search box');
report(strpos($searchHtml, '&lt;script&gt;alert') !== false, 'search value escaped in rendered search box');
report(strpos($searchHtml, 'value="') !== false, 'search box renders a value attribute');

$emptyHtml = minirank_render_list([]);
report(strpos($emptyHtml, 'No keywords match your search.') !== false, 'empty list renders no-results message');
report(strpos($emptyHtml, '<table') === false, 'empty list renders no table');

$rows = minirank_search_keywords($db, 'brand new', $today);
$html = minirank_render_list($rows);
report(strpos($html, '—') !== false, 'missing current position renders em dash');
report(strpos($html, '(not enough history)') !== false, 'missing history is labelled in rendered list');

$db = null;
$rows = null;
$all = null;
$ws = null;
$map = null;
$html = null;
$searchHtml = null;
$emptyHtml = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}