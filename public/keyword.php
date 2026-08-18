<?php

declare(strict_types=1);

function minirank_detail_404(): void
{
    header('HTTP/1.1 404 Not Found');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Keyword not found</title></head><body>'
        . '<h1>Keyword not found.</h1>'
        . '<p><a href="index.php">Back to keyword list</a></p>'
        . '</body></html>';
    exit;
}

try {
    $config = require __DIR__ . '/../config.php';

    $siteName = is_array($config) && isset($config['site_name']) && is_string($config['site_name'])
        ? $config['site_name']
        : 'MiniRank';
    $siteUrl = is_array($config) && isset($config['site_url']) && is_string($config['site_url'])
        ? $config['site_url']
        : '#';

    require_once __DIR__ . '/../src/db.php';
    require_once __DIR__ . '/../src/keyword.php';
    require_once __DIR__ . '/../src/list.php';
    require_once __DIR__ . '/../src/session.php';

    $id = minirank_parse_id($_GET['id'] ?? null);
    if ($id === null) {
        minirank_detail_404();
    }

    $db = minirank_db();
    $keyword = minirank_find_keyword($db, $id);
    if ($keyword === null) {
        minirank_detail_404();
    }

    $history = minirank_position_history($db, $id);
    $csrfToken = minirank_csrf_token();
    $flash = minirank_flash_pull();
} catch (Throwable $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Something went wrong</title></head><body>'
        . '<h1>Something went wrong.</h1><p>Please try again later.</p></body></html>';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($keyword['phrase'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <p class="back"><a href="index.php">&larr; Back to keywords</a></p>
        <h1><?= htmlspecialchars($keyword['phrase'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="added">Added on <?= htmlspecialchars($keyword['created_at'], ENT_QUOTES, 'UTF-8') ?></p>
    </header>
    <main>
        <?= minirank_flash_render($flash) ?>
        <div class="actions">
            <a href="keyword-edit.php?id=<?= (int) $keyword['id'] ?>" class="edit-link">Edit keyword</a>
            <a href="export.php?id=<?= (int) $keyword['id'] ?>" class="edit-link">Export CSV</a>
            <?= minirank_render_delete_form($keyword, $csrfToken) ?>
        </div>
        <h2>Position history</h2>
        <?= minirank_render_chart($history) ?>
        <?= minirank_render_history($history) ?>
    </main>
</body>
</html>