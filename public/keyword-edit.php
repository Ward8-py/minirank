<?php

declare(strict_types=1);

function minirank_edit_404(): void
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
        minirank_edit_404();
    }

    $db = minirank_db();
    $keyword = minirank_find_keyword($db, $id);
    if ($keyword === null) {
        minirank_edit_404();
    }

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
    <title>Edit keyword &middot; <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <p class="back"><a href="keyword.php?id=<?= (int) $keyword['id'] ?>">&larr; Back to keyword</a></p>
        <h1>Edit keyword</h1>
        <?php if ($siteUrl !== '#') { ?>
            <p class="site-url"><a href="<?= htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') ?></a></p>
        <?php } ?>
    </header>
    <main>
        <?= minirank_flash_render($flash) ?>
        <?= minirank_render_edit_form($keyword, $csrfToken) ?>
    </main>
</body>
</html>