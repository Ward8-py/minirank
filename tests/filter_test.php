<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/list.php';
require_once __DIR__ . '/../src/refresh.php';

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
        . 'minirank_filter_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function idsOf(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) $row['id'];
    }
    sort($ids);
    return $ids;
}

$today = '2026-08-17';
$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();

echo "## Filter: movement parsing\n";
report(minirank_parse_movement(['movement' => 'improved']) === 'improved', 'improved is a valid movement');
report(minirank_parse_movement(['movement' => 'declined']) === 'declined', 'declined is a valid movement');
report(minirank_parse_movement(['movement' => 'stable']) === 'stable', 'stable is a valid movement');
report(
    minirank_parse_movement(['movement' => 'not_enough_history']) === 'not_enough_history',
    'not_enough_history is a valid movement'
);
report(minirank_parse_movement(['movement' => 'banana']) === null, 'unknown movement is ignored');
report(minirank_parse_movement([]) === null, 'missing movement is ignored');
report(minirank_parse_movement(['movement' => [1, 2]]) === null, 'array movement is ignored');
report(minirank_parse_movement(['movement' => 42]) === null, 'non-string movement is ignored');

echo "\n## Filter: dataset setup\n";
$improvedId = insertKeyword($db, 'improved kw');
insertPosition($db, $improvedId, '2026-08-10', 20);
insertPosition($db, $improvedId, '2026-08-17', 10);
$declinedId = insertKeyword($db, 'declined kw');
insertPosition($db, $declinedId, '2026-08-10', 10);
insertPosition($db, $declinedId, '2026-08-17', 20);
$stableId = insertKeyword($db, 'stable kw');
insertPosition($db, $stableId, '2026-08-10', 15);
insertPosition($db, $stableId, '2026-08-17', 15);
$noBaselineId = insertKeyword($db, 'no baseline kw');
insertPosition($db, $noBaselineId, '2026-08-17', 5);
$noPositionsId = insertKeyword($db, 'no positions kw');

$all = minirank_search_keywords($db, '', $today);
report(count($all) === 5, 'unfiltered search returns all 5 keywords');

echo "\n## Filter: each movement selects its exact set\n";
$improved = minirank_search_keywords($db, '', $today, 'improved');
report(idsOf($improved) === [$improvedId], 'improved filter returns only the improved keyword');
$declined = minirank_search_keywords($db, '', $today, 'declined');
report(idsOf($declined) === [$declinedId], 'declined filter returns only the declined keyword');
$stable = minirank_search_keywords($db, '', $today, 'stable');
report(idsOf($stable) === [$stableId], 'stable filter returns the stable keyword with a real baseline');
report(
    in_array($noBaselineId, idsOf($stable), true) === false
        && in_array($noPositionsId, idsOf($stable), true) === false,
    'stable filter excludes keywords with not enough history'
);
$noHistory = minirank_search_keywords($db, '', $today, 'not_enough_history');
report(
    idsOf($noHistory) === [$noBaselineId, $noPositionsId],
    'not_enough_history filter returns only the no-baseline and no-positions keywords'
);

echo "\n## Filter: the four movement categories add up to the unfiltered total\n";
$total = count(minirank_search_keywords($db, '', $today));
$sum = count($improved) + count($declined) + count($stable) + count($noHistory);
report($sum === $total, "four movement counts ($sum) equal the unfiltered total ($total)");
$union = array_merge($improved, $declined, $stable, $noHistory);
report(
    idsOf($union) === idsOf($all),
    'the four movement results partition the unfiltered keyword set exactly'
);

echo "\n## Filter: combined search and movement\n";
$rows = minirank_search_keywords($db, 'no', $today, 'not_enough_history');
report(
    idsOf($rows) === [$noBaselineId, $noPositionsId],
    'search "no" + not_enough_history matches the two no-history keywords'
);
$rows = minirank_search_keywords($db, 'improved', $today, 'improved');
report(idsOf($rows) === [$improvedId], 'search "improved" + improved matches only the improved keyword');
$rows = minirank_search_keywords($db, 'kw', $today, 'stable');
report(idsOf($rows) === [$stableId], 'search "kw" + stable returns only the real-baseline stable keyword');
$rows = minirank_search_keywords($db, 'zzz', $today, 'declined');
report($rows === [], 'search with no match returns no rows even with a movement filter');

echo "\n## Filter: invalid movement is inert\n";
$rows = minirank_search_keywords($db, '', $today, 'banana');
report(idsOf($rows) === idsOf($all), 'unknown movement value returns the full list');
$rows = minirank_search_keywords($db, '', $today, null);
report(idsOf($rows) === idsOf($all), 'null movement with blank query returns the full list');

echo "\n## Filter: render search form\n";
$html = minirank_render_search('seo', 'improved');
report(strpos($html, 'name="movement"') !== false, 'search form renders the movement select');
report(strpos($html, 'id="movement"') !== false, 'movement select carries a stable id for refresh.js');
foreach (['improved', 'declined', 'stable', 'not_enough_history'] as $value) {
    report(
        strpos($html, 'value="' . $value . '"') !== false,
        "search form offers the $value movement option"
    );
}
report(
    strpos($html, '<option value="improved" selected>') !== false,
    'active movement is marked selected in the rendered form'
);
report(strpos($html, '<option value="declined" selected>') === false, 'inactive movement is not marked selected');
$html = minirank_render_search('"><script>alert(1)</script>', 'stable');
report(strpos($html, '<script>alert') === false, 'raw script tag absent from rendered search form');
report(strpos($html, '&lt;script&gt;alert') !== false, 'hostile query escaped in rendered search form');

echo "\n## Filter: refresh total is separate from the filtered keyword count\n";
$total = count(minirank_search_keywords($db, '', $today));
$result = minirank_refresh($db, $today, '', 'improved');
report($result['refreshed'] === $total, 'refresh reports the total keyword count, not the filtered count');
report(count($result['keywords']) <= $total, 'filtered refresh returns no more than the total');
report(count($result['keywords']) > 0, 'filtered refresh still returns the deterministic improved keyword');
$allImproved = true;
foreach ($result['keywords'] as $kw) {
    if ($kw['direction'] !== MINIRANK_TREND_IMPROVED) {
        $allImproved = false;
    }
}
report($allImproved, 'every keyword in the filtered refresh payload is improved');
$result = minirank_refresh($db, $today, '', null);
report(count($result['keywords']) === $total, 'unfiltered refresh returns every keyword');

$db = null;
$all = null;
$improved = null;
$declined = null;
$stable = null;
$noHistory = null;
$union = null;
$result = null;
$html = null;
$rows = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}