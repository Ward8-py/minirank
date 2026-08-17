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

function minirank_validate_phrase(string $phrase): array
{
    $phrase = trim($phrase);
    if ($phrase === '') {
        return ['ok' => false, 'phrase' => $phrase, 'error' => 'Keyword cannot be blank.'];
    }
    $length = function_exists('mb_strlen')
        ? mb_strlen($phrase, 'UTF-8')
        : strlen($phrase);
    if ($length > 120) {
        return ['ok' => false, 'phrase' => $phrase, 'error' => 'Keyword must be 120 characters or fewer.'];
    }
    return ['ok' => true, 'phrase' => $phrase, 'error' => null];
}

function minirank_keyword_exists(PDO $pdo, string $phrase, ?int $excludeId = null): bool
{
    $sql = 'SELECT 1 FROM keywords WHERE phrase = :phrase COLLATE NOCASE';
    $params = [':phrase' => $phrase];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() !== false;
}

function minirank_create_keyword(PDO $pdo, string $phrase): array
{
    $validated = minirank_validate_phrase($phrase);
    if (!$validated['ok']) {
        return ['ok' => false, 'id' => null, 'error' => $validated['error']];
    }
    $phrase = $validated['phrase'];
    if (minirank_keyword_exists($pdo, $phrase)) {
        return ['ok' => false, 'id' => null, 'error' => 'That keyword already exists.'];
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO keywords (phrase) VALUES (:phrase)');
        $stmt->execute([':phrase' => $phrase]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['ok' => false, 'id' => null, 'error' => 'That keyword already exists.'];
        }
        throw $e;
    }
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'error' => null];
}

function minirank_update_keyword(PDO $pdo, int $id, string $phrase): array
{
    if (minirank_find_keyword($pdo, $id) === null) {
        return ['ok' => false, 'id' => $id, 'error' => 'Keyword not found.', 'not_found' => true];
    }
    $validated = minirank_validate_phrase($phrase);
    if (!$validated['ok']) {
        return ['ok' => false, 'id' => $id, 'error' => $validated['error'], 'not_found' => false];
    }
    $phrase = $validated['phrase'];
    if (minirank_keyword_exists($pdo, $phrase, $id)) {
        return ['ok' => false, 'id' => $id, 'error' => 'That keyword already exists.', 'not_found' => false];
    }
    try {
        $stmt = $pdo->prepare('UPDATE keywords SET phrase = :phrase WHERE id = :id');
        $stmt->execute([':phrase' => $phrase, ':id' => $id]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['ok' => false, 'id' => $id, 'error' => 'That keyword already exists.', 'not_found' => false];
        }
        throw $e;
    }
    return ['ok' => true, 'id' => $id, 'error' => null, 'not_found' => false];
}

function minirank_delete_keyword(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM keywords WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}
