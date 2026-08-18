<?php

declare(strict_types=1);

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

function runCmd(string $cmd): array
{
    $out = [];
    $rc = -1;
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, $out];
}

function freePort(): ?int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return null;
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($name) || preg_match('/:(\d+)$/', $name, $m) !== 1) {
        return null;
    }
    return (int) $m[1];
}

function httpGet(string $url): array
{
    $context = stream_context_create(
        ['http' => ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0]]
    );
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    return [$status, $body === false ? '' : $body];
}

function httpGetRetry(string $url): array
{
    for ($i = 0; $i < 60; $i++) {
        [$status, $body] = httpGet($url);
        if ($status !== 0) {
            return [$status, $body];
        }
        usleep(500000);
    }
    return [0, ''];
}

echo "## Docker smoke: preconditions\n";
[$rc, ] = runCmd('docker version --format "{{.Server.Version}}"');
report($rc === 0, 'docker daemon is reachable');
[$rc, ] = runCmd('docker compose version');
report($rc === 0, 'docker compose plugin is available');
if ($rc !== 0) {
    echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
    exit(1);
}

echo "\n## Docker smoke: build, start, seed, HTTP 200\n";
$port = freePort();
report($port !== null, 'a free host port is available');
if ($port === null) {
    echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
    exit(1);
}
putenv('MINIRANK_PORT=' . $port);

$proj = 'minirank-test-' . bin2hex(random_bytes(4));
$compose = __DIR__ . '/../docker-compose.yml';
$base = 'http://127.0.0.1:' . $port;

[$rc, $out] = runCmd('docker compose -p ' . $proj . ' -f ' . $compose . ' up -d --build');
report($rc === 0, 'isolated project builds and starts via docker compose up');

[$status, $body] = httpGetRetry($base . '/');
report($status === 200, 'app responds HTTP 200 on the exposed port');
if ($status === 200) {
    $seedPhrases = [
        'seo tools',
        'best project management software',
        'free rank tracker',
        'keyword rank checker',
        'local seo checklist',
        'ai content marketing',
    ];
    foreach ($seedPhrases as $phrase) {
        report(strpos($body, $phrase) !== false, 'seeded phrase present: ' . $phrase);
    }
    report(
        strpos($body, 'action="actions/keyword-create.php"') !== false,
        'add form posts to the create endpoint'
    );
    report(strpos($body, 'name="csrf_token"') !== false, 'csrf token field is embedded');
}

echo "\n## Docker smoke: seed probe and persistence across restart\n";
[$rc, $out] = runCmd('docker compose -p ' . $proj . ' -f ' . $compose . ' exec -T app php bin/seed.php');
$probe = implode("\n", $out);
report($rc === 0 && strpos($probe, 'no new rows') !== false, 'first boot seed is present (probe adds no rows)');

[$rc, ] = runCmd('docker compose -p ' . $proj . ' -f ' . $compose . ' restart');
report($rc === 0, 'docker compose restart succeeds');

[$status, $body] = httpGetRetry($base . '/');
report($status === 200, 'app responds HTTP 200 after restart');

[$rc, $out] = runCmd('docker compose -p ' . $proj . ' -f ' . $compose . ' exec -T app php bin/seed.php');
$probe = implode("\n", $out);
report(
    $rc === 0 && strpos($probe, 'no new rows') !== false,
    'SQLite data persisted across restart (probe still sees all rows)'
);

echo "\n## Docker smoke: clean up only the isolated project\n";
[$rc, ] = runCmd('docker compose -p ' . $proj . ' -f ' . $compose . ' down -v --rmi local');
report($rc === 0, 'isolated project torn down with down -v --rmi local');

$filters = [
    'container' => 'docker ps -a -q --filter',
    'volume' => 'docker volume ls -q --filter',
    'network' => 'docker network ls -q --filter',
    'image' => 'docker image ls -q --filter',
];
foreach ($filters as $type => $cmd) {
    [$rc, $out] = runCmd($cmd . ' label=com.docker.compose.project=' . $proj);
    $left = array_values(array_filter(array_map('trim', $out), static fn ($line): bool => $line !== ''));
    report($rc === 0 && $left === [], 'no project ' . $type . ' remains');
}

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}