<?php

declare(strict_types=1);

function minirank_parse_id($value): ?int
{
    if (!is_string($value)) {
        return null;
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        return null;
    }
    return $id;
}

function minirank_find_keyword(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, phrase, created_at FROM keywords
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'phrase' => (string) $row['phrase'],
        'created_at' => (string) $row['created_at'],
    ];
}

function minirank_position_history(PDO $pdo, int $keywordId): array
{
    $stmt = $pdo->prepare(
        'SELECT recorded_on, position FROM positions
         WHERE keyword_id = :keyword_id
         ORDER BY recorded_on DESC, id DESC'
    );
    $stmt->execute([':keyword_id' => $keywordId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'recorded_on' => (string) $row['recorded_on'],
            'position' => (int) $row['position'],
        ];
    }
    return $rows;
}

function minirank_render_history(array $rows): string
{
    if ($rows === []) {
        return '<p class="no-results">No position history yet for this keyword.</p>';
    }

    $html = '<table class="history"><thead><tr>'
        . '<th>Date</th>'
        . '<th>Position</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $date = htmlspecialchars((string) $row['recorded_on'], ENT_QUOTES, 'UTF-8');
        $position = htmlspecialchars((string) $row['position'], ENT_QUOTES, 'UTF-8');
        $html .= '<tr>'
            . '<td>' . $date . '</td>'
            . '<td class="position">' . $position . '</td>'
            . '</tr>';
    }

    return $html . '</tbody></table>';
}