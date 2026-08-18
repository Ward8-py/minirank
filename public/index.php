<?php

declare(strict_types=1);

try {
    $config = require __DIR__ . '/../config.php';

    $siteName = is_array($config) && isset($config['site_name']) && is_string($config['site_name'])
        ? $config['site_name']
        : 'MiniRank';
    $siteUrl = is_array($config) && isset($config['site_url']) && is_string($config['site_url'])
        ? $config['site_url']
        : '#';

    require_once __DIR__ . '/../src/db.php';
    require_once __DIR__ . '/../src/list.php';
    require_once __DIR__ . '/../src/session.php';

    $query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    $rows = minirank_search_keywords(minirank_db(), $query);
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
    <title><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?> &middot; Keyword Rankings</title>
    <link rel="stylesheet" href="style.css">
    <script src="js/refresh.js" defer></script>
</head>
<body>
    <header>
        <h1><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($siteUrl !== '#') { ?>
            <p class="site-url"><a href="<?= htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') ?></a></p>
        <?php } ?>
    </header>
    <main>
        <?= minirank_flash_render($flash) ?>
        <?= minirank_render_add_form($csrfToken) ?>
        <?= minirank_render_search($query) ?>
        <?= minirank_render_refresh_control($csrfToken) ?>
        <?= minirank_render_list($rows) ?>
    </main>
</body>
</html>