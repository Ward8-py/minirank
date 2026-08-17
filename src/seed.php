<?php

declare(strict_types=1);

const MINIRANK_SEED_KEYWORDS = [
    'seo tools',
    'best project management software',
    'free rank tracker',
    'keyword rank checker',
    'local seo checklist',
    'ai content marketing',
];

const MINIRANK_SEED_DAYS = 30;

function minirank_seed_position(string $phrase, int $dayOffset): int
{
    switch ($phrase) {
        case 'seo tools':
            return 45 - $dayOffset;
        case 'best project management software':
            return 8 + $dayOffset;
        case 'free rank tracker':
            return 20;
        case 'keyword rank checker':
            return 80 - (2 * $dayOffset);
        case 'local seo checklist':
            return 6 + (2 * $dayOffset);
        case 'ai content marketing':
            return 15 + (($dayOffset % 7) - 3);
    }
    throw new InvalidArgumentException('Unknown seed phrase: ' . $phrase);
}

function minirank_seed(PDO $pdo): array
{
    $insertedKeywords = 0;
    $insertedPositions = 0;

    $pdo->beginTransaction();
    try {
        $insertKeyword = $pdo->prepare('INSERT OR IGNORE INTO keywords (phrase) VALUES (:phrase)');
        $selectKeyword = $pdo->prepare('SELECT id FROM keywords WHERE phrase = :phrase COLLATE NOCASE');
        $insertPosition = $pdo->prepare(
            'INSERT OR IGNORE INTO positions (keyword_id, recorded_on, position)
             VALUES (:keyword_id, :recorded_on, :position)'
        );

        $today = date('Y-m-d');
        $keywordIds = [];

        foreach (MINIRANK_SEED_KEYWORDS as $phrase) {
            $insertKeyword->execute([':phrase' => $phrase]);
            if ($insertKeyword->rowCount() > 0) {
                $insertedKeywords++;
            }
            $selectKeyword->execute([':phrase' => $phrase]);
            $id = $selectKeyword->fetchColumn();
            if ($id === false) {
                throw new RuntimeException('Seed keyword missing after insert: ' . $phrase);
            }
            $keywordIds[$phrase] = (int) $id;
        }

        for ($offset = MINIRANK_SEED_DAYS - 1; $offset >= 0; $offset--) {
            $recordedOn = date('Y-m-d', strtotime("-$offset days", strtotime($today)));
            $dayOffset = MINIRANK_SEED_DAYS - 1 - $offset;
            foreach (MINIRANK_SEED_KEYWORDS as $phrase) {
                $insertPosition->execute([
                    ':keyword_id' => $keywordIds[$phrase],
                    ':recorded_on' => $recordedOn,
                    ':position' => minirank_seed_position($phrase, $dayOffset),
                ]);
                if ($insertPosition->rowCount() > 0) {
                    $insertedPositions++;
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['keywords' => $insertedKeywords, 'positions' => $insertedPositions];
}
