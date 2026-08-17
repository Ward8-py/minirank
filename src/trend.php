<?php

declare(strict_types=1);

const MINIRANK_TREND_IMPROVED = 'improved';
const MINIRANK_TREND_DECLINED = 'declined';
const MINIRANK_TREND_STABLE = 'stable';

function minirank_position_on_or_before(PDO $pdo, int $keywordId, string $date): ?int
{
    $stmt = $pdo->prepare(
        'SELECT position FROM positions
         WHERE keyword_id = :keyword_id AND recorded_on <= :date
         ORDER BY recorded_on DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([':keyword_id' => $keywordId, ':date' => $date]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

function minirank_current_position(PDO $pdo, int $keywordId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT position FROM positions
         WHERE keyword_id = :keyword_id
         ORDER BY recorded_on DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([':keyword_id' => $keywordId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

function minirank_trend_from_positions(?int $current, ?int $baseline): array
{
    if ($current === null || $baseline === null) {
        return [
            'direction' => MINIRANK_TREND_STABLE,
            'current' => $current,
            'baseline' => $baseline,
            'not_enough_history' => true,
        ];
    }

    if ($current < $baseline) {
        $direction = MINIRANK_TREND_IMPROVED;
    } elseif ($current > $baseline) {
        $direction = MINIRANK_TREND_DECLINED;
    } else {
        $direction = MINIRANK_TREND_STABLE;
    }

    return [
        'direction' => $direction,
        'current' => $current,
        'baseline' => $baseline,
        'not_enough_history' => false,
    ];
}

function minirank_trend(PDO $pdo, int $keywordId, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $current = minirank_current_position($pdo, $keywordId);
    $baselineDate = date('Y-m-d', strtotime($today . ' -7 days'));
    $baseline = minirank_position_on_or_before($pdo, $keywordId, $baselineDate);

    return minirank_trend_from_positions($current, $baseline);
}
