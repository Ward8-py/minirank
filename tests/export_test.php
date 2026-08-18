<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/keyword.php';
require_once __DIR__ . '/../src/export.php';

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
        . 'minirank_export_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

echo "## Export: CSV cell encoding\n";
report(minirank_csv_cell('plain') === 'plain', 'plain cell returned unquoted');
report(minirank_csv_cell('2026-08-17') === '2026-08-17', 'date cell returned unquoted');
report(minirank_csv_cell('42') === '42', 'numeric cell returned unquoted');
report(minirank_csv_cell('') === '""', 'empty cell returned as a quoted empty field');
report(minirank_csv_cell('has,comma') === '"has,comma"', 'comma cell wrapped in quotes');
report(minirank_csv_cell('say "hi"') === '"say ""hi"""', 'embedded quotes are doubled inside quotes');
report(minirank_csv_cell("line\nbreak") === "\"line\nbreak\"", 'embedded newline cell wrapped in quotes');
report(minirank_csv_cell("a\rb") === "\"a\rb\"", 'embedded carriage return cell wrapped in quotes');
report(minirank_csv_cell("tab\there") === "tab\there", 'tab inside a cell does not force quoting');

echo "\n## Export: formula injection neutralization\n";
report(minirank_csv_neutralize('=SUM(A1)') === "'=SUM(A1)", 'leading equals sign is neutralized');
report(minirank_csv_neutralize('+cmd') === "'+cmd", 'leading plus sign is neutralized');
report(minirank_csv_neutralize('-5') === "'-5", 'leading minus sign is neutralized');
report(minirank_csv_neutralize('@foo') === "'@foo", 'leading at sign is neutralized');
report(minirank_csv_neutralize("\tlead") === "'\tlead", 'leading tab is neutralized');
report(minirank_csv_neutralize("\rlead") === "'\rlead", 'leading carriage return is neutralized');
report(minirank_csv_neutralize('hello') === 'hello', 'ordinary text is left untouched');
report(minirank_csv_neutralize('2026-08-17') === '2026-08-17', 'date is left untouched');
report(minirank_csv_neutralize('42') === '42', 'number is left untouched');
report(minirank_csv_neutralize('') === '', 'empty value is left untouched');

echo "\n## Export: CSV row building\n";
report(
    minirank_csv_rows(['phrase' => 'p'], []) === "Keyword,Date,Position\n",
    'empty history produces header row only'
);
$rows = minirank_csv_rows(
    ['phrase' => 'seo tools'],
    [
        ['recorded_on' => '2026-08-17', 'position' => 5],
        ['recorded_on' => '2026-08-13', 'position' => 12],
        ['recorded_on' => '2026-08-10', 'position' => 20],
    ]
);
report(
    $rows === "Keyword,Date,Position\nseo tools,2026-08-17,5\nseo tools,2026-08-13,12\nseo tools,2026-08-10,20\n",
    'rows preserve history order with phrase, date, and position per row'
);
$rows = minirank_csv_rows(
    ['phrase' => '=1+1'],
    [['recorded_on' => '2026-08-17', 'position' => 3]]
);
report(strpos($rows, "'=1+1,2026-08-17,3") !== false, 'formula-leading phrase is neutralized in the row');
report(strpos($rows, "\n=1+1,") === false, 'no row begins with an unguarded formula cell');
$rows = minirank_csv_rows(
    ['phrase' => '=a,b'],
    [['recorded_on' => '2026-08-17', 'position' => 3]]
);
report(strpos($rows, "\"'=a,b\"") !== false, 'comma-bearing formula phrase is quoted after neutralization');
$rows = minirank_csv_rows(
    ['phrase' => 'say "hi"'],
    [['recorded_on' => '2026-08-17', 'position' => 3]]
);
report(strpos($rows, '"say ""hi"""') !== false, 'quote-bearing phrase has quotes doubled in the row');
report(
    minirank_csv_rows(
        ['phrase' => 'x'],
        [['recorded_on' => '2026-08-17', 'position' => 42]]
    ) === "Keyword,Date,Position\nx,2026-08-17,42\n",
    'integer positions and string dates serialize correctly'
);

echo "\n## Export: fixed numeric filename\n";
report(minirank_export_filename(3) === '3-history.csv', 'filename is the fixed numeric <id>-history.csv');
report(minirank_export_filename(7) === '7-history.csv', 'different id yields its own numeric filename');
report(minirank_export_filename(999999) === '999999-history.csv', 'large id yields a valid numeric filename');
$filename = minirank_export_filename(1);
report(
    strpos($filename, "\r") === false && strpos($filename, "\n") === false,
    'filename contains no carriage return or newline'
);

echo "\n## Export: integration with position history ordering\n";
$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();
$kw = insertKeyword($db, 'history order');
insertPosition($db, $kw, '2026-08-10', 20);
insertPosition($db, $kw, '2026-08-13', 12);
insertPosition($db, $kw, '2026-08-17', 5);
$keyword = minirank_find_keyword($db, $kw);
$csv = minirank_csv_rows($keyword, minirank_position_history($db, $kw));
$lines = explode("\n", $csv);
report(
    $lines[0] === 'Keyword,Date,Position'
        && $lines[1] === 'history order,2026-08-17,5'
        && $lines[2] === 'history order,2026-08-13,12'
        && $lines[3] === 'history order,2026-08-10,20',
    'database history exports newest first, matching the rendered table'
);

$db = null;
$rows = null;
$filename = null;
$csv = null;
$lines = null;
$keyword = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}