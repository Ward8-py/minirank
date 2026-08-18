<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/seed.php';
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

function throwsThrowable(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

function scratchPath(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'minirank_refresh_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function todayRows(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        'SELECT keyword_id, position FROM positions WHERE recorded_on = :d ORDER BY keyword_id'
    );
    $stmt->execute([':d' => $date]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['keyword_id']] = (int) $row['position'];
    }
    return $map;
}

function todayRowCount(PDO $pdo, string $date): int
{
    return count(todayRows($pdo, $date));
}

function positionsSnapshot(PDO $pdo): string
{
    return json_encode($pdo->query(
        'SELECT id, keyword_id, recorded_on, position FROM positions ORDER BY id'
    )->fetchAll());
}

$today = '2026-08-17';
$scratch1 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch1);
$db = minirank_db();

echo "## Refresh: pure drift helper\n";
report(minirank_drift_position(50, 5) === 55, 'drift +5 from 50 becomes 55');
report(minirank_drift_position(50, -5) === 45, 'drift -5 from 50 becomes 45');
report(minirank_drift_position(1, -5) === 1, 'drift -5 from 1 clamps to 1');
report(minirank_drift_position(1, 5) === 6, 'drift +5 from 1 becomes 6');
report(minirank_drift_position(100, 5) === 100, 'drift +5 from 100 clamps to 100');
report(minirank_drift_position(100, -5) === 95, 'drift -5 from 100 becomes 95');
report(minirank_drift_position(1, 0) === 1, 'zero drift from 1 stays 1');
report(minirank_drift_position(50, 0) === 50, 'zero drift from 50 stays 50');
report(minirank_drift_position(100, 0) === 100, 'zero drift from 100 stays 100');
$allInRange = true;
for ($p = 1; $p <= 100; $p++) {
    for ($s = -5; $s <= 5; $s++) {
        $out = minirank_drift_position($p, $s);
        if ($out < 1 || $out > 100) {
            $allInRange = false;
        }
    }
}
report($allInRange, 'drift output is always within 1..100 for all positions and steps');

echo "\n## Refresh: keyword with no history\n";
$noHistoryId = insertKeyword($db, 'no history kw');
$result = minirank_refresh($db, $today);
$rows = todayRows($db, $today);
report(isset($rows[$noHistoryId]), 'refresh inserts a today row for a keyword with no history');
$stored = isset($rows[$noHistoryId]) ? $rows[$noHistoryId] : 0;
report($stored >= 1 && $stored <= 100, 'no-history keyword gets a position within 1..100');
$search = minirank_search_keywords($db, '', $today);
$found = null;
foreach ($search as $row) {
    if ((int) $row['id'] === $noHistoryId) {
        $found = $row;
        break;
    }
}
report(
    is_array($found)
        && $found['current_position'] === $stored
        && $found['baseline_position'] === null
        && $found['direction'] === MINIRANK_TREND_STABLE
        && $found['not_enough_history'] === true,
    'no-history keyword reports its stored position with stable / not enough history'
);

echo "\n## Refresh: same-day UPSERT\n";
$historyId = insertKeyword($db, 'has history');
insertPosition($db, $historyId, '2026-08-10', 30);
insertPosition($db, $historyId, '2026-08-16', 25);
$result = minirank_refresh($db, $today);
report($result['refreshed'] === 2, 'refresh reports 2 keywords refreshed');
report(todayRowCount($db, $today) === 2, 'exactly one today row per keyword after refresh');
report(isset(todayRows($db, $today)[$historyId]), 'keyword with history gets its today row upserted');
report(
    (int) $db->query('SELECT COUNT(*) FROM positions WHERE keyword_id = ' . (int) $historyId)->fetchColumn() === 3,
    'existing history rows are untouched by the upsert'
);

echo "\n## Refresh: repeated refreshes stay idempotent per day\n";
$result = minirank_refresh($db, $today);
report(todayRowCount($db, $today) === 2, 'second refresh same day still leaves one today row per keyword');
$allInRange = true;
foreach (todayRows($db, $today) as $position) {
    if ($position < 1 || $position > 100) {
        $allInRange = false;
    }
}
report($allInRange, 'repeated refresh keeps all today positions within 1..100');
report(todayRowCount($db, $today) === 2, 'repeated refresh adds no duplicate today rows');
report(
    (int) $db->query('SELECT COUNT(*) FROM positions')->fetchColumn() === 4,
    'total positions stay at 4: no-history kw (1 today) + has-history kw (2 prior + 1 today)'
);

echo "\n## Refresh: determinism under a fixed mt seed\n";
$scratch2 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch2);
minirank_db_close();
$db2 = minirank_db();
minirank_seed($db2);

$scratch3 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch3);
minirank_db_close();
$db3 = minirank_db();
minirank_seed($db3);

mt_srand(42);
$resultA = minirank_refresh($db2, $today);
$valuesA = todayRows($db2, $today);
mt_srand(42);
$resultB = minirank_refresh($db3, $today);
$valuesB = todayRows($db3, $today);
report($valuesA === $valuesB, 'two identical databases refresh to identical today values under the same seed');
report($resultA['refreshed'] === $resultB['refreshed'], 'refreshed counts match under the same seed');

echo "\n## Refresh: transaction atomicity with pre-existing today rows\n";
$scratch4 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch4);
minirank_db_close();
$db4 = minirank_db();
minirank_seed($db4);
$canaryId = insertKeyword($db4, 'canary keyword');
$db4->exec(
    'CREATE TRIGGER trg_break BEFORE INSERT ON positions'
    . ' WHEN NEW.keyword_id = ' . (int) $canaryId
    . ' BEGIN'
    . '   INSERT INTO no_such_table (id) VALUES (NEW.id);'
    . ' END'
);

mt_srand(12345);
$drifts = [];
for ($i = 0; $i < 6; $i++) {
    $drifts[] = mt_rand(-5, 5);
}
$nonZero = 0;
foreach ($drifts as $d) {
    if ($d !== 0) {
        $nonZero++;
    }
}
report($nonZero > 0, 'chosen seed guarantees at least one nonzero drift step (rollback check is meaningful)');

$before = positionsSnapshot($db4);
$todayBefore = todayRows($db4, $today);
mt_srand(12345);
report(
    throwsThrowable(function () use ($db4, $today): void {
        minirank_refresh($db4, $today);
    }),
    'refresh throws when a later position upsert fails'
);
report(
    positionsSnapshot($db4) === $before,
    'failed refresh leaves the complete position state unchanged, including pre-existing today rows'
);
report(
    todayRows($db4, $today) === $todayBefore,
    'pre-existing today rows survive the rollback unchanged'
);
report(todayRowCount($db4, $today) === 6, 'no partial today rows remain after the rollback');
report(
    (int) $db4->query('SELECT COUNT(*) FROM positions')->fetchColumn() === 180,
    'position count is exactly the seeded 180 after the rollback'
);

echo "\n## Refresh: render control\n";
$html = minirank_render_refresh_control('abc123');
report(
    strpos($html, 'id="refresh-positions"') !== false
        && strpos($html, 'type="button"') !== false,
    'refresh control renders a type=button refresh control'
);
report(
    strpos($html, 'id="refresh-csrf" value="abc123"') !== false,
    'refresh control embeds the csrf token in a hidden input'
);
report(
    strpos($html, 'id="refresh-status"') !== false
        && strpos($html, 'aria-live="polite"') !== false,
    'refresh control renders an aria-live status region'
);
$html = minirank_render_refresh_control('"><script>alert(1)</script>');
report(strpos($html, '<script>alert') === false, 'raw script tag absent from rendered refresh control');
report(strpos($html, '&quot;&gt;&lt;script&gt;alert') !== false, 'hostile csrf token escaped in refresh control');

$db = null;
$db2 = null;
$db3 = null;
$db4 = null;
$result = null;
$rows = null;
$search = null;
$found = null;
$valuesA = null;
$valuesB = null;
$drifts = null;
$html = null;
$before = null;
$todayBefore = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch1);
cleanup($scratch2);
cleanup($scratch3);
cleanup($scratch4);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}