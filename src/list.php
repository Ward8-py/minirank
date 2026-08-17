<?php

declare(strict_types=1);

require_once __DIR__ . '/trend.php';

function minirank_like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

function minirank_like_pattern(string $query): string
{
    return '%' . minirank_like_escape($query) . '%';
}

function minirank_search_keywords(PDO $pdo, string $query, ?string $today = null): array
{
    $query = trim($query);
    $today = $today ?? date('Y-m-d');
    $baselineDate = date('Y-m-d', strtotime($today . ' -7 days'));

    $sql = 'SELECT
                k.id,
                k.phrase,
                (SELECT position FROM positions p
                 WHERE p.keyword_id = k.id
                 ORDER BY recorded_on DESC, id DESC LIMIT 1) AS current_position,
                (SELECT position FROM positions p
                 WHERE p.keyword_id = k.id AND p.recorded_on <= :baseline
                 ORDER BY recorded_on DESC, id DESC LIMIT 1) AS baseline_position
            FROM keywords k';

    $params = [':baseline' => $baselineDate];

    if ($query !== '') {
        $sql .= " WHERE k.phrase LIKE :pattern ESCAPE '\\'";
        $params[':pattern'] = minirank_like_pattern($query);
    }

    $sql .= ' ORDER BY k.phrase COLLATE NOCASE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $current = $row['current_position'] === null ? null : (int) $row['current_position'];
        $baseline = $row['baseline_position'] === null ? null : (int) $row['baseline_position'];
        $trend = minirank_trend_from_positions($current, $baseline);
        $rows[] = [
            'id' => (int) $row['id'],
            'phrase' => (string) $row['phrase'],
            'current_position' => $current,
            'baseline_position' => $baseline,
            'direction' => $trend['direction'],
            'not_enough_history' => $trend['not_enough_history'],
        ];
    }
    return $rows;
}

function minirank_render_search(string $query): string
{
    $value = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');
    return '<form method="get" action="" class="search">'
        . '<label for="q">Search keywords</label>'
        . '<input type="text" id="q" name="q" value="' . $value . '">'
        . '<button type="submit">Search</button>'
        . '</form>';
}

function minirank_render_list(array $rows): string
{
    if ($rows === []) {
        return '<p class="no-results">No keywords match your search.</p>';
    }

    $html = '<table class="keywords"><thead><tr>'
        . '<th>Keyword</th>'
        . '<th>Current position</th>'
        . '<th>7-day trend</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $phrase = htmlspecialchars((string) $row['phrase'], ENT_QUOTES, 'UTF-8');
        $current = htmlspecialchars(
            $row['current_position'] === null ? '—' : (string) $row['current_position'],
            ENT_QUOTES,
            'UTF-8'
        );
        $direction = (string) $row['direction'];
        $label = $row['not_enough_history']
            ? $direction . ' (not enough history)'
            : $direction;
        $html .= '<tr>'
            . '<td>' . $phrase . '</td>'
            . '<td class="position">' . $current . '</td>'
            . '<td class="trend-' . $direction . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }

    return $html . '</tbody></table>';
}