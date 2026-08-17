<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/seed.php';
require_once __DIR__ . '/../src/trend.php';

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
        . 'minirank_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function seedPositionsMap(PDO $pdo): array
{
    $map = [];
    $rows = $pdo->query(
        'SELECT k.phrase, p.recorded_on, p.position
         FROM positions p JOIN keywords k ON k.id = p.keyword_id
         ORDER BY k.phrase, p.recorded_on'
    )->fetchAll();
    foreach ($rows as $row) {
        $map[$row['phrase']][$row['recorded_on']] = (int) $row['position'];
    }
    return $map;
}

echo "## Seed: fresh database\n";
$scratch1 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch1);
$db = minirank_db();

$first = minirank_seed($db);
report($first['keywords'] === 6, 'seed returns 6 newly inserted keywords');
report($first['positions'] === 180, 'seed returns 180 newly inserted positions');

$phrases = $db->query('SELECT phrase FROM keywords ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
report($phrases === MINIRANK_SEED_KEYWORDS, 'seed creates exactly the 6 demo keywords in order');

$totalPositions = (int) $db->query('SELECT COUNT(*) FROM positions')->fetchColumn();
report($totalPositions === 180, 'seed creates 180 position rows (6 keywords x 30 days)');

$consecutive = true;
$kwRows = $db->query('SELECT id FROM keywords')->fetchAll();
foreach ($kwRows as $kw) {
    $datesStmt = $db->prepare('SELECT recorded_on FROM positions WHERE keyword_id = :id ORDER BY recorded_on');
    $datesStmt->execute([':id' => $kw['id']]);
    $dates = $datesStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($dates) !== MINIRANK_SEED_DAYS) {
        $consecutive = false;
        continue;
    }
    for ($i = 1; $i < count($dates); $i++) {
        $expected = date('Y-m-d', strtotime($dates[$i - 1] . ' +1 day'));
        if ($dates[$i] !== $expected) {
            $consecutive = false;
            break;
        }
    }
}
report($consecutive, 'each keyword has 30 consecutive daily positions with no gaps or duplicate dates');

$badValue = false;
$posValues = $db->query('SELECT position FROM positions')->fetchAll(PDO::FETCH_COLUMN);
foreach ($posValues as $value) {
    if ($value < 1 || $value > 100) {
        $badValue = true;
        break;
    }
}
report(!$badValue, 'all seeded positions are within 1..100');

$pristineMap = seedPositionsMap($db);

echo "\n## Seed: idempotence\n";
$second = minirank_seed($db);
report($second['keywords'] === 0 && $second['positions'] === 0, 'rerunning seed is a no-op (0 new rows)');
report((int) $db->query('SELECT COUNT(*) FROM keywords')->fetchColumn() === 6, 'rerun does not duplicate keywords');
report((int) $db->query('SELECT COUNT(*) FROM positions')->fetchColumn() === 180, 'rerun does not duplicate positions');

echo "\n## Seed: never overwrites existing data\n";
$db->exec('UPDATE positions SET position = 99 WHERE id = (SELECT MIN(id) FROM positions)');
$changed = $db->query('SELECT position FROM positions ORDER BY id LIMIT 1')->fetchColumn();
report((int) $changed === 99, 'test setup: a seeded position was edited to 99');
minirank_seed($db);
$after = $db->query('SELECT position FROM positions ORDER BY id LIMIT 1')->fetchColumn();
report((int) $after === 99, 'rerunning seed does not overwrite an existing changed position');
report((int) $db->query('SELECT COUNT(*) FROM positions')->fetchColumn() === 180, 'position count unchanged after overwrite rerun');

echo "\n## Seed: determinism across fresh databases\n";
$scratch2 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch2);
minirank_db_close();
$db2 = minirank_db();
minirank_seed($db2);
$secondMap = seedPositionsMap($db2);
report($secondMap === $pristineMap, 'a fresh seed produces an identical date->position mapping');
report(minirank_seed($db2) === ['keywords' => 0, 'positions' => 0], 'rerun on the fresh database is also a no-op');

echo "\n## Seed: case-insensitive pre-existing phrase\n";
$scratch3 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch3);
minirank_db_close();
$db3 = minirank_db();
insertKeyword($db3, 'SEO TOOLS');
minirank_seed($db3);
report((int) $db3->query('SELECT COUNT(*) FROM keywords')->fetchColumn() === 6, 'seed with pre-existing "SEO TOOLS" still yields exactly 6 keywords');
report((int) $db3->query('SELECT COUNT(*) FROM positions')->fetchColumn() === 180, 'positions seeded for the case-insensitive keyword too');

echo "\n## Seed: transaction atomicity\n";
$scratch4 = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch4);
minirank_db_close();
$db4 = minirank_db();
$db4->exec(
    "CREATE TRIGGER trg_break BEFORE INSERT ON positions
     BEGIN
       INSERT INTO no_such_table (id) VALUES (NEW.id);
     END"
);
report(
    throwsThrowable(function () use ($db4): void {
        minirank_seed($db4);
    }),
    'seed throws when a later position insert fails'
);
report((int) $db4->query('SELECT COUNT(*) FROM keywords')->fetchColumn() === 0, 'failed seed rolled back keyword inserts (atomic)');
report((int) $db4->query('SELECT COUNT(*) FROM positions')->fetchColumn() === 0, 'failed seed left no position rows (atomic)');

echo "\n## Trend: lower is better\n";
$today = '2026-08-17';

$kw = insertKeyword($db2, 'trend improved');
insertPosition($db2, $kw, '2026-08-10', 20);
insertPosition($db2, $kw, '2026-08-17', 10);
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['direction'] === MINIRANK_TREND_IMPROVED
        && $trend['current'] === 10
        && $trend['baseline'] === 20
        && $trend['not_enough_history'] === false,
    'improved when current (10) is below baseline (20)'
);

$kw = insertKeyword($db2, 'trend declined');
insertPosition($db2, $kw, '2026-08-10', 10);
insertPosition($db2, $kw, '2026-08-17', 20);
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['direction'] === MINIRANK_TREND_DECLINED
        && $trend['current'] === 20
        && $trend['baseline'] === 10,
    'declined when current (20) is above baseline (10)'
);

$kw = insertKeyword($db2, 'trend stable');
insertPosition($db2, $kw, '2026-08-10', 15);
insertPosition($db2, $kw, '2026-08-17', 15);
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['direction'] === MINIRANK_TREND_STABLE
        && $trend['current'] === 15
        && $trend['baseline'] === 15
        && $trend['not_enough_history'] === false,
    'stable when current equals baseline'
);

echo "\n## Trend: missing history\n";
$kw = insertKeyword($db2, 'trend no history');
insertPosition($db2, $kw, '2026-08-14', 12);
insertPosition($db2, $kw, '2026-08-17', 11);
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['direction'] === MINIRANK_TREND_STABLE
        && $trend['not_enough_history'] === true
        && $trend['baseline'] === null
        && $trend['current'] === 11,
    'missing baseline -> stable with "not enough history"'
);

$kw = insertKeyword($db2, 'trend empty');
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['direction'] === MINIRANK_TREND_STABLE
        && $trend['not_enough_history'] === true
        && $trend['current'] === null
        && $trend['baseline'] === null,
    'keyword with no positions -> stable with "not enough history"'
);

$trend = minirank_trend($db2, 999999, $today);
report(
    $trend['direction'] === MINIRANK_TREND_STABLE
        && $trend['not_enough_history'] === true
        && $trend['current'] === null,
    'unknown keyword id degrades to stable/not enough history'
);

echo "\n## Trend: position selection rules\n";
$kw = insertKeyword($db2, 'trend newest');
insertPosition($db2, $kw, '2026-08-10', 20);
insertPosition($db2, $kw, '2026-08-15', 12);
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['current'] === 12 && $trend['direction'] === MINIRANK_TREND_IMPROVED,
    'current uses the newest recorded position even when it is before today'
);

$kw = insertKeyword($db2, 'trend boundary');
insertPosition($db2, $kw, '2026-08-09', 30);
insertPosition($db2, $kw, '2026-08-10', 20);
insertPosition($db2, $kw, '2026-08-17', 10);
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['baseline'] === 20 && $trend['direction'] === MINIRANK_TREND_IMPROVED,
    'baseline is the newest position on or before today-7 (boundary row used)'
);

$kw = insertKeyword($db2, 'trend sparse');
insertPosition($db2, $kw, '2026-08-05', 40);
insertPosition($db2, $kw, '2026-08-12', 20);
insertPosition($db2, $kw, '2026-08-17', 10);
$trend = minirank_trend($db2, $kw, $today);
report(
    $trend['baseline'] === 40 && $trend['direction'] === MINIRANK_TREND_IMPROVED,
    'sparse data: baseline uses the newest row on or before today-7'
);

echo "\n## Trend: seeded demo keywords\n";
$expectedDirections = [
    'seo tools' => MINIRANK_TREND_IMPROVED,
    'best project management software' => MINIRANK_TREND_DECLINED,
    'free rank tracker' => MINIRANK_TREND_STABLE,
    'keyword rank checker' => MINIRANK_TREND_IMPROVED,
    'local seo checklist' => MINIRANK_TREND_DECLINED,
    'ai content marketing' => MINIRANK_TREND_STABLE,
];
$allMatch = true;
$stmt = $db2->prepare('SELECT id FROM keywords WHERE phrase = :phrase');
foreach ($expectedDirections as $phrase => $direction) {
    $stmt->execute([':phrase' => $phrase]);
    $id = (int) $stmt->fetchColumn();
    $trend = minirank_trend($db2, $id);
    if ($trend['direction'] !== $direction) {
        $allMatch = false;
    }
}
report($allMatch, 'seeded demo keywords produce the designed trend directions');

echo "\n## CLI seed command\n";
$cliScratch = scratchPath();
$cliCmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../bin/seed.php');
putenv('MINIRANK_DB_PATH=' . $cliScratch);
minirank_db_close();

$output1 = [];
$rc1 = 0;
exec($cliCmd . ' 2>&1', $output1, $rc1);
$joined1 = implode("\n", $output1);
report($rc1 === 0, 'CLI seed exits 0 on first run');
report(
    strpos($joined1, 'Seeded 6') !== false && strpos($joined1, '180 position row(s)') !== false,
    'first CLI run reports 6 keywords and 180 positions'
);

$output2 = [];
$rc2 = 0;
exec($cliCmd . ' 2>&1', $output2, $rc2);
$joined2 = implode("\n", $output2);
report($rc2 === 0, 'CLI seed exits 0 on second run');
report(strpos($joined2, 'no new rows') !== false, 'second CLI run reports no new rows');

$dbCli = minirank_db();
report((int) $dbCli->query('SELECT COUNT(*) FROM keywords')->fetchColumn() === 6, 'CLI-seeded db has exactly 6 keywords after two runs');
report((int) $dbCli->query('SELECT COUNT(*) FROM positions')->fetchColumn() === 180, 'CLI-seeded db has exactly 180 positions after two runs');

$dirPath = sys_get_temp_dir();
putenv('MINIRANK_DB_PATH=' . $dirPath);
minirank_db_close();
$output3 = [];
$rc3 = 0;
exec($cliCmd . ' 2>&1', $output3, $rc3);
$joined3 = implode("\n", $output3);
report($rc3 !== 0, 'CLI seed exits non-zero when the database cannot be opened');
report(
    strpos($joined3, 'Seed failed') !== false
        && strpos($joined3, 'unable to open') === false
        && strpos($joined3, 'SQLSTATE') === false
        && strpos($joined3, 'PDOException') === false,
    'CLI failure output is generic with no raw exception details'
);
putenv('MINIRANK_DB_PATH');

$db = null;
$db2 = null;
$db3 = null;
$db4 = null;
$dbCli = null;
$datesStmt = null;
$stmt = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch1);
cleanup($scratch2);
cleanup($scratch3);
cleanup($scratch4);
cleanup($cliScratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}
