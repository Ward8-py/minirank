<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/seed.php';

try {
    $result = minirank_seed(minirank_db());
    if ($result['keywords'] === 0 && $result['positions'] === 0) {
        fwrite(STDOUT, "Already seeded; no new rows inserted.\n");
    } else {
        fwrite(
            STDOUT,
            sprintf(
                "Seeded %d keyword(s), %d position row(s).\n",
                $result['keywords'],
                $result['positions']
            )
        );
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Seed failed.\n");
    exit(1);
}
