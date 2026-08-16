<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';

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

function throwsPdo(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (PDOException $e) {
        return true;
    }
}

function normalizePath(string $path): string
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function cleanup(string $path): void
{
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'minirank_test_' . bin2hex(random_bytes(4)) . '.sqlite';

echo "## Environment\n";
report(PHP_VERSION_ID >= 80000, 'PHP 8.0+ (' . PHP_VERSION . ')');
report(in_array('sqlite', PDO::getAvailableDrivers(), true), 'pdo_sqlite driver available');
report(extension_loaded('pdo_sqlite'), 'pdo_sqlite extension loaded');
$memory = new PDO('sqlite::memory:');
report($memory->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'sqlite driver opens a connection');

echo "\n## DB path\n";
$defaultExpected = normalizePath(dirname(__DIR__) . '/data/minirank.sqlite');
report(normalizePath(minirank_db_path()) === $defaultExpected, 'default db path is data/minirank.sqlite');
putenv('MINIRANK_DB_PATH=' . $scratch);
report(minirank_db_path() === $scratch, 'MINIRANK_DB_PATH env override respected');

echo "\n## Connection and schema\n";
$db = minirank_db();
report($db instanceof PDO, 'minirank_db() returns PDO');
report(is_file($scratch), 'database file created');
report(minirank_db() === $db, 'connection is a singleton');
try {
    minirank_schema($db);
    report(true, 'schema is idempotent (second run)');
} catch (PDOException $e) {
    report(false, 'schema is idempotent (second run)');
}
$fk = (int) $db->query('PRAGMA foreign_keys')->fetchColumn();
report($fk === 1, 'PRAGMA foreign_keys is ON (1)');
$busy = (int) $db->query('PRAGMA busy_timeout')->fetchColumn();
report($busy === 5000, 'PRAGMA busy_timeout is 5000');

$tables = $db->query(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('keywords','positions') ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);
report($tables === ['keywords', 'positions'], 'keywords and positions tables exist');

$kwCols = $db->query("SELECT name FROM pragma_table_info('keywords')")->fetchAll(PDO::FETCH_COLUMN);
report($kwCols === ['id', 'phrase', 'created_at'], 'keywords columns correct');
$posCols = $db->query("SELECT name FROM pragma_table_info('positions')")->fetchAll(PDO::FETCH_COLUMN);
report($posCols === ['id', 'keyword_id', 'recorded_on', 'position', 'created_at'], 'positions columns correct');

echo "\n## Keywords constraints\n";
$insertKeyword = $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)');
$insertKeyword->execute([':phrase' => 'seo tools']);
report(true, 'insert a valid 1..120 phrase succeeds');
report(
    throwsPdo(function () use ($db): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => 'SEO TOOLS']);
    }),
    'case-insensitive duplicate phrase rejected'
);
report(
    throwsPdo(function () use ($db): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => '']);
    }),
    'empty phrase rejected'
);
$long = str_repeat('a', 121);
report(
    throwsPdo(function () use ($db, $long): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => $long]);
    }),
    '121-char phrase rejected'
);
$boundary = str_repeat('b', 120);
report(
    !throwsPdo(function () use ($db, $boundary): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => $boundary]);
    }),
    '120-char phrase accepted'
);
report(
    !throwsPdo(function () use ($db): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => 'a']);
    }),
    'single-char phrase accepted'
);
report(
    throwsPdo(function () use ($db): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => '   ']);
    }),
    'whitespace-only phrase rejected'
);
report(
    throwsPdo(function () use ($db): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => ' seo ']);
    }),
    'phrase with leading/trailing spaces rejected'
);
report(
    throwsPdo(function () use ($db): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => "\tseo"]);
    }),
    'phrase with leading tab rejected'
);
report(
    throwsPdo(function () use ($db): void {
        $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)')->execute([':phrase' => 'seo' . "\n"]);
    }),
    'phrase with trailing newline rejected'
);

$stmt = $db->prepare('SELECT created_at FROM keywords WHERE phrase = :phrase');
$stmt->execute([':phrase' => 'seo tools']);
$created = $stmt->fetchColumn();
report(
    is_string($created) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $created) === 1,
    'created_at stored as YYYY-MM-DD'
);

echo "\n## Positions constraints\n";
$kwIdStmt = $db->prepare('SELECT id FROM keywords WHERE phrase = :phrase');
$kwIdStmt->execute([':phrase' => 'seo tools']);
$kwId = (int) $kwIdStmt->fetchColumn();

$insertPosition = $db->prepare(
    'INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:keyword_id, :recorded_on, :position)'
);
$insertPosition->execute([':keyword_id' => $kwId, ':recorded_on' => '2026-08-10', ':position' => 5]);
report(true, 'insert a valid position succeeds');
report(
    throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-08-11', ':p' => 0]);
    }),
    'position 0 rejected'
);
report(
    throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-08-11', ':p' => 101]);
    }),
    'position 101 rejected'
);
report(
    throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-08-11', ':p' => '5.5']);
    }),
    'non-integer position 5.5 rejected'
);
report(
    !throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-08-13', ':p' => 1]);
    }),
    'position 1 accepted'
);
report(
    !throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-08-14', ':p' => 100]);
    }),
    'position 100 accepted'
);
report(
    throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-8-1', ':p' => 5]);
    }),
    'date 2026-8-1 rejected'
);
report(
    throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026/08/01', ':p' => 5]);
    }),
    'date 2026/08/01 rejected'
);
report(
    throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '20260801', ':p' => 5]);
    }),
    'date 20260801 rejected'
);
report(
    !throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-08-15', ':p' => 5]);
    }),
    'date 2026-08-15 accepted'
);
report(
    throwsPdo(function () use ($db, $kwId): void {
        $db->prepare('INSERT INTO positions (keyword_id, recorded_on, position) VALUES (:k, :d, :p)')
            ->execute([':k' => $kwId, ':d' => '2026-08-10', ':p' => 6]);
    }),
    'duplicate (keyword_id, recorded_on) rejected'
);

echo "\n## Cascade delete\n";
$cascadeStmt = $db->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)');
$cascadeStmt->execute([':phrase' => 'cascade target']);
$cascadeId = (int) $db->lastInsertId();
$insertPosition->execute([':keyword_id' => $cascadeId, ':recorded_on' => '2026-08-12', ':position' => 3]);
$db->prepare('DELETE FROM keywords WHERE id = :id')->execute([':id' => $cascadeId]);
$countStmt = $db->prepare('SELECT COUNT(*) FROM positions WHERE keyword_id = :keyword_id');
$countStmt->execute([':keyword_id' => $cascadeId]);
report((int) $countStmt->fetchColumn() === 0, 'deleting a keyword cascades to its positions');

$db = null;
$memory = null;
$insertKeyword = null;
$stmt = null;
$kwIdStmt = null;
$insertPosition = null;
$cascadeStmt = null;
$countStmt = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}