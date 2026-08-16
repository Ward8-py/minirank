<?php

declare(strict_types=1);

const MINIRANK_SCHEMA_DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS keywords (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    phrase     TEXT    NOT NULL
        CHECK (length(phrase) BETWEEN 1 AND 120)
        CHECK (phrase = trim(phrase, ' ' || char(9) || char(10) || char(13))),
    created_at TEXT    NOT NULL DEFAULT (date('now', 'localtime'))
        CHECK (created_at GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
    UNIQUE (phrase COLLATE NOCASE)
);

CREATE TABLE IF NOT EXISTS positions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword_id  INTEGER NOT NULL,
    recorded_on TEXT    NOT NULL
        CHECK (recorded_on GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
    position    INTEGER NOT NULL
        CHECK (position BETWEEN 1 AND 100)
        CHECK (position = CAST(position AS INTEGER)),
    created_at  TEXT    NOT NULL DEFAULT (date('now', 'localtime')),
    FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE CASCADE,
    UNIQUE (keyword_id, recorded_on)
);

CREATE INDEX IF NOT EXISTS idx_positions_recorded_on ON positions (recorded_on);
SQL;

function minirank_schema(PDO $pdo): void
{
    $pdo->exec(MINIRANK_SCHEMA_DDL);
}