<?php

declare(strict_types=1);

require_once __DIR__ . '/list.php';

function minirank_drift_position(int $position, int $step): int
{
    $position = $position + $step;
    if ($position < 1) {
        return 1;
    }
    if ($position > 100) {
        return 100;
    }
    return $position;
}

function minirank_refresh(
    PDO $pdo,
    ?string $today = null,
    string $query = '',
    ?string $movement = null
): array {
    $today = $today ?? date('Y-m-d');

    $keywords = [];
    foreach ($pdo->query('SELECT id, phrase FROM keywords ORDER BY id')->fetchAll() as $row) {
        $keywords[] = [
            'id' => (int) $row['id'],
            'phrase' => (string) $row['phrase'],
        ];
    }

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(
            'INSERT INTO positions (keyword_id, recorded_on, position)
             VALUES (:keyword_id, :recorded_on, :position)
             ON CONFLICT (keyword_id, recorded_on)
             DO UPDATE SET position = excluded.position'
        );
        $todayPos = $pdo->prepare(
            'SELECT position FROM positions
             WHERE keyword_id = :keyword_id AND recorded_on = :recorded_on
             LIMIT 1'
        );
        $latestPos = $pdo->prepare(
            'SELECT position FROM positions
             WHERE keyword_id = :keyword_id
             ORDER BY recorded_on DESC, id DESC
             LIMIT 1'
        );

        foreach ($keywords as $keyword) {
            $todayPos->execute([':keyword_id' => $keyword['id'], ':recorded_on' => $today]);
            $base = $todayPos->fetchColumn();
            if ($base === false) {
                $latestPos->execute([':keyword_id' => $keyword['id']]);
                $base = $latestPos->fetchColumn();
            }

            if ($base === false) {
                $position = mt_rand(1, 100);
            } else {
                $position = minirank_drift_position((int) $base, mt_rand(-5, 5));
            }

            $upsert->execute([
                ':keyword_id' => $keyword['id'],
                ':recorded_on' => $today,
                ':position' => $position,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'refreshed' => count($keywords),
        'keywords' => minirank_search_keywords($pdo, $query, $today, $movement),
    ];
}