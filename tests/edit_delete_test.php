<?php

declare(strict_types=1);

putenv('MINIRANK_DB_PATH');
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/keyword.php';
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
        . 'minirank_edit_delete_test_' . bin2hex(random_bytes(4)) . '.sqlite';
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

function positionCount(PDO $pdo, int $keywordId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM positions WHERE keyword_id = :k');
    $stmt->execute([':k' => $keywordId]);
    return (int) $stmt->fetchColumn();
}

function orphanCount(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM positions
         WHERE keyword_id NOT IN (SELECT id FROM keywords)'
    )->fetchColumn();
}

function phraseOf(PDO $pdo, int $id): ?string
{
    $stmt = $pdo->prepare('SELECT phrase FROM keywords WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

$scratch = scratchPath();
putenv('MINIRANK_DB_PATH=' . $scratch);
$db = minirank_db();

echo "## Edit/Delete: keyword_exists with self-exclusion\n";
$kw = insertKeyword($db, 'seo tools');
$other = insertKeyword($db, 'local seo');
report(minirank_keyword_exists($db, 'seo tools') === true, 'exists true for an existing phrase');
report(minirank_keyword_exists($db, 'seo tools', $kw) === false, 'self-exclusion: own phrase no longer counts as a duplicate');
report(minirank_keyword_exists($db, 'SEO TOOLS', $kw) === false, 'self-exclusion is case-insensitive for own phrase');
report(minirank_keyword_exists($db, 'seo tools', $other) === true, 'exclude only excludes the given id, not others');
report(minirank_keyword_exists($db, 'never seen') === false, 'exists false for a missing phrase');
report(minirank_keyword_exists($db, 'never seen', $kw) === false, 'exists false for a missing phrase even with exclude id');

echo "\n## Edit/Delete: update keyword\n";
$kw = insertKeyword($db, 'rename me');
$r = minirank_update_keyword($db, $kw, 'renamed keyword');
report($r['ok'] === true && $r['id'] === $kw && $r['not_found'] === false, 'valid update returns ok and preserves the id');
report(phraseOf($db, $kw) === 'renamed keyword', 'updated phrase is stored');
$r = minirank_update_keyword($db, $kw, '  padded rename  ');
report($r['ok'] === true, 'update accepts a padded phrase');
report(phraseOf($db, $kw) === 'padded rename', 'updated phrase is trimmed on store');
$r = minirank_update_keyword($db, $kw, 'Padded Rename');
report($r['ok'] === true && phraseOf($db, $kw) === 'Padded Rename', 'keeping the same phrase in a different case is allowed');
$r = minirank_update_keyword($db, $kw, 'Padded Rename');
report($r['ok'] === true, 'submitting the exact current phrase is allowed');

echo "\n## Edit/Delete: duplicate update rejected\n";
$r = minirank_update_keyword($db, $kw, 'SEO TOOLS');
report(
    $r['ok'] === false && is_string($r['error']) && strpos($r['error'], 'already exists') !== false,
    'case-insensitive duplicate update rejected with friendly error'
);
report(phraseOf($db, $kw) === 'Padded Rename', 'duplicate update leaves the phrase unchanged');

echo "\n## Edit/Delete: blank and oversized update rejected\n";
$before = phraseOf($db, $kw);
$r = minirank_update_keyword($db, $kw, '   ');
report($r['ok'] === false && is_string($r['error']) && strpos($r['error'], 'blank') !== false, 'blank update rejected');
$r = minirank_update_keyword($db, $kw, str_repeat('x', 121));
report(
    $r['ok'] === false && is_string($r['error']) && strpos($r['error'], '120') !== false,
    'oversized update rejected'
);
report(phraseOf($db, $kw) === $before, 'rejected updates leave the phrase unchanged');

echo "\n## Edit/Delete: update of unknown or deleted keyword\n";
$r = minirank_update_keyword($db, 999999, 'any phrase');
report($r['ok'] === false && $r['not_found'] === true, 'unknown id update reports not found');
$doomed = insertKeyword($db, 'doomed for update');
$db->prepare('DELETE FROM keywords WHERE id = :id')->execute([':id' => $doomed]);
$r = minirank_update_keyword($db, $doomed, 'any phrase');
report($r['ok'] === false && $r['not_found'] === true, 'deleted keyword id update reports not found');

echo "\n## Edit/Delete: delete keyword with cascade isolation\n";
$survivor = insertKeyword($db, 'survivor');
insertPosition($db, $survivor, '2026-08-10', 5);
insertPosition($db, $survivor, '2026-08-17', 3);
$target = insertKeyword($db, 'delete target');
insertPosition($db, $target, '2026-08-10', 20);
insertPosition($db, $target, '2026-08-13', 12);
insertPosition($db, $target, '2026-08-17', 9);
report(positionCount($db, $survivor) === 2, 'setup: survivor has 2 position rows');
report(positionCount($db, $target) === 3, 'setup: target has 3 position rows');
report(orphanCount($db) === 0, 'setup: global orphan position count is 0');
report(minirank_delete_keyword($db, $target) === true, 'delete returns true for an existing keyword');
report(positionCount($db, $target) === 0, 'deleted keyword has no remaining positions (cascade)');
report(positionCount($db, $survivor) === 2, 'survivor positions are untouched by the delete');
report(phraseOf($db, $survivor) === 'survivor', 'survivor keyword itself is untouched');
report(orphanCount($db) === 0, 'delete leaves no orphan position rows behind');
report(minirank_find_keyword($db, $target) === null, 'deleted keyword id returns null from find_keyword');
report(minirank_delete_keyword($db, $target) === false, 'deleting an already-deleted keyword returns false');
report(minirank_delete_keyword($db, 999999) === false, 'deleting a never-existing id returns false');
report(orphanCount($db) === 0, 'failed deletes add no orphan position rows');

echo "\n## Edit/Delete: edit form rendering\n";
$html = minirank_render_edit_form(['id' => 7, 'phrase' => 'seo tools'], 'tok123');
report(
    strpos($html, 'method="post"') !== false
        && strpos($html, 'action="actions/keyword-update.php"') !== false,
    'edit form posts directly to the dedicated update endpoint'
);
report(
    strpos($html, 'name="id" value="7"') !== false,
    'edit form embeds the keyword id'
);
report(
    strpos($html, 'name="csrf_token" value="tok123"') !== false,
    'edit form embeds the csrf token'
);
report(
    strpos($html, 'name="phrase" value="seo tools"') !== false
        && strpos($html, 'maxlength="120"') !== false,
    'edit form prefills the phrase capped at 120 chars'
);
$html = minirank_render_edit_form(['id' => 7, 'phrase' => '<script>alert("xss")</script>'], 'tok123');
report(strpos($html, '<script>alert') === false, 'raw script tag absent from prefilled edit form');
report(strpos($html, '&lt;script&gt;alert') !== false, 'prefilled hostile phrase escaped in edit form');
$html = minirank_render_edit_form(['id' => 7, 'phrase' => 'seo tools'], '"><script>alert(1)</script>');
report(strpos($html, '<script>alert') === false, 'raw script tag absent from edit form token');
report(strpos($html, '&quot;&gt;&lt;script&gt;alert') !== false, 'hostile csrf token escaped in edit form');

echo "\n## Edit/Delete: delete form rendering\n";
$html = minirank_render_delete_form(['id' => 7, 'phrase' => 'seo tools'], 'tok123');
report(
    strpos($html, 'method="post"') !== false
        && strpos($html, 'action="actions/keyword-delete.php"') !== false,
    'delete form posts directly to the dedicated delete endpoint'
);
report(strpos($html, 'name="id" value="7"') !== false, 'delete form embeds the keyword id');
report(
    strpos($html, 'name="csrf_token" value="tok123"') !== false,
    'delete form embeds the csrf token'
);
report(strpos($html, 'class="btn-danger"') !== false, 'delete form uses a danger button');
$html = minirank_render_delete_form(['id' => 7, 'phrase' => 'seo tools'], '"><script>alert(1)</script>');
report(strpos($html, '<script>alert') === false, 'raw script tag absent from delete form token');
report(strpos($html, '&quot;&gt;&lt;script&gt;alert') !== false, 'hostile csrf token escaped in delete form');

$db = null;
$r = null;
$html = null;
minirank_db_close();
gc_collect_cycles();
cleanup($scratch);

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}