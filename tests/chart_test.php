<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/keyword.php';

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

echo "## Chart: point geometry (fixed inverted 1-100 scale)\n";
$points = minirank_chart_points([
    ['recorded_on' => '2026-08-17', 'position' => 100],
    ['recorded_on' => '2026-08-10', 'position' => 1],
]);
report(count($points) === 2, 'two rows produce two points');
report(
    $points[0]['y'] === 14.0 && $points[1]['y'] === 156.0,
    'position 1 maps to the top (y=14) and position 100 to the bottom (y=156)'
);
report($points[0]['y'] < $points[1]['y'], 'position 1 (best) sits above position 100 (worst)');

$points = minirank_chart_points([
    ['recorded_on' => '2026-08-17', 'position' => 10],
    ['recorded_on' => '2026-08-13', 'position' => 25],
    ['recorded_on' => '2026-08-10', 'position' => 50],
]);
report(
    $points[0]['x'] === 24.0 && $points[1]['x'] === 178.0 && $points[2]['x'] === 332.0,
    'three points are evenly spaced across the plot width'
);
report(
    $points[0]['y'] > $points[1]['y'] && $points[1]['y'] > $points[2]['y'],
    'improving (lower) positions rise toward the top of the chart'
);

$points = minirank_chart_points([
    ['recorded_on' => '2026-08-17', 'position' => 40],
]);
report(count($points) === 1, 'single row produces a single point');
report($points[0]['x'] === 178.0, 'single point is horizontally centered');
report(
    $points[0]['y'] === round(14.0 + (39 * 142.0 / 99.0), 1),
    'single point y reflects its position on the 1-100 scale'
);

report(minirank_chart_points([]) === [], 'empty history produces no points');

$newestFirst = minirank_chart_points([
    ['recorded_on' => '2026-08-17', 'position' => 20],
    ['recorded_on' => '2026-08-10', 'position' => 40],
]);
report(
    $newestFirst[0]['x'] < $newestFirst[1]['x'],
    'newest-first history is drawn oldest to newest (left to right)'
);

echo "\n## Chart: render\n";
$html = minirank_render_chart([]);
report($html === '', 'empty history renders no chart');

$html = minirank_render_chart([
    ['recorded_on' => '2026-08-10', 'position' => 45],
    ['recorded_on' => '2026-08-13', 'position' => 30],
    ['recorded_on' => '2026-08-17', 'position' => 20],
]);
report(strpos($html, '<figure class="chart">') !== false, 'chart is wrapped in a figure');
report(strpos($html, '<svg class="chart-svg"') !== false, 'renders an svg element');
report(strpos($html, 'viewBox="0 0 340 170"') !== false, 'svg declares a fixed viewBox');
report(substr_count($html, '<line class="chart-grid"') === 2, 'renders gridlines for positions 1 and 100');
report(
    strpos($html, '>1</text>') !== false && strpos($html, '>100</text>') !== false,
    'renders visible 1 and 100 axis labels'
);
preg_match_all('/class="chart-axis" x="4" y="(\d+)"/', $html, $labelYs);
report(
    isset($labelYs[1][0], $labelYs[1][1]) && (int) $labelYs[1][0] < (int) $labelYs[1][1],
    'top label (1) renders above bottom label (100)'
);
report(substr_count($html, '<polyline') === 1, 'renders exactly one polyline');
preg_match('/points="([^"]+)"/', $html, $m);
report(isset($m[1]) && count(explode(' ', $m[1])) === 3, 'polyline has one point per history row');
report(substr_count($html, '<circle class="chart-dot"') === 3, 'renders one dot per history row');
preg_match('/^([\d.]+),([\d.]+)/', $m[1] ?? '', $first);
preg_match('/([\d.]+),([\d.]+)$/', $m[1] ?? '', $last);
report(
    isset($first[1], $last[1]) && (float) $first[1] < (float) $last[1],
    'oldest point is leftmost and newest is rightmost'
);

$single = minirank_render_chart([
    ['recorded_on' => '2026-08-17', 'position' => 40],
]);
report(substr_count($single, '<circle class="chart-dot"') === 1, 'single-point chart renders exactly one dot');
report(strpos($single, '<polyline') === false, 'single-point chart renders no polyline');
report(
    strpos($single, '>1</text>') !== false && strpos($single, '>100</text>') !== false,
    'single-point chart still shows the 1 and 100 labels'
);

$evil = minirank_render_chart([
    ['recorded_on' => '<script>alert(1)</script>', 'position' => 3],
]);
report(strpos($evil, '<script>alert') === false, 'no raw script tag in rendered chart');
report(strpos($evil, '&lt;script&gt;alert') !== false, 'chart caption escapes hostile dates');
report(strpos($evil, '<table') === false, 'chart markup contains no table');

echo "\n" . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . " failed\n";
if ($GLOBALS['failed'] > 0) {
    exit(1);
}