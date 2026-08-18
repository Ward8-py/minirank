<?php

declare(strict_types=1);

function minirank_csv_cell(string $value): string
{
    if ($value !== ''
        && strpos($value, ',') === false
        && strpos($value, '"') === false
        && strpos($value, "\r") === false
        && strpos($value, "\n") === false) {
        return $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

function minirank_csv_neutralize(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $first = $value[0];
    if ($first === '='
        || $first === '+'
        || $first === '-'
        || $first === '@'
        || $first === "\t"
        || $first === "\r") {
        return "'" . $value;
    }
    return $value;
}

function minirank_csv_rows(array $keyword, array $history): string
{
    $phrase = isset($keyword['phrase']) ? (string) $keyword['phrase'] : '';
    $rows = "Keyword,Date,Position\n";
    foreach ($history as $row) {
        $date = isset($row['recorded_on']) ? (string) $row['recorded_on'] : '';
        $position = isset($row['position']) ? (string) $row['position'] : '';
        $rows .= minirank_csv_cell(minirank_csv_neutralize($phrase)) . ','
            . minirank_csv_cell($date) . ','
            . minirank_csv_cell($position) . "\n";
    }
    return $rows;
}

function minirank_export_filename(int $id): string
{
    return $id . '-history.csv';
}