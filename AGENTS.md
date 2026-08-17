# AGENTS.md

## Status
M1 (Keywords CRUD) is **in progress, not complete**. Completed slices: database foundation — `src/db.php` (reusable PDO connection), `src/schema.php` (SQLite DDL), and `tests/db_foundation_test.php` (reusable acceptance checks); deterministic seed and seven-day trend — `src/seed.php` (transactional `minirank_seed()`, `INSERT OR IGNORE`, 6 demo keywords x 30 consecutive days anchored to today), `src/trend.php` (`minirank_trend()` lower-is-better: improved/declined/stable/not enough history), `bin/seed.php` (idempotent CLI, generic error output), and `tests/seed_trend_test.php` (idempotence, no-overwrite, determinism, atomicity, trend cases, CLI smoke). Verified commands:
- Lint: `php -l src/db.php`, `php -l src/schema.php`, `php -l src/seed.php`, `php -l src/trend.php`, `php -l bin/seed.php`, `php -l tests/db_foundation_test.php`, `php -l tests/seed_trend_test.php`
- Tests: `php tests/db_foundation_test.php` (38 checks, exit 0 on pass)
- Tests: `php tests/seed_trend_test.php` (37 checks, exit 0 on pass, no warnings, no leftover temp files)
- CLI: `php bin/seed.php` (exit 0; rerun is a no-op; respects `MINIRANK_DB_PATH`; failure prints only "Seed failed.")
Not yet implemented: keyword add/edit/delete pages, POST actions, and refresh (same-day UPSERT). Update this section as milestones land.

M4 (Keyword list & search) is **complete**. Completed slices: `public/index.php` (GET list + case-insensitive search page with escaped output and generic 500 on unexpected failures), `config.php` (site name/URL for the one configured website), `src/list.php` (`minirank_search_keywords()` with single-query current+baseline via correlated subqueries, `%`/`_`/`\` LIKE escaping, `minirank_render_search()`/`minirank_render_list()` escaping helpers), `src/trend.php` (extracted pure `minirank_trend_from_positions()`, reused by `minirank_trend()`), `public/style.css` (minimal), and `tests/list_test.php`. Verified commands:
- Lint: `php -l src/trend.php`, `php -l src/list.php`, `php -l config.php`, `php -l public/index.php`, `php -l tests/list_test.php`
- Tests: `php tests/db_foundation_test.php` (38 checks, exit 0)
- Tests: `php tests/seed_trend_test.php` (37 checks, exit 0, no warnings, no leftover temp files)
- Tests: `php tests/list_test.php` (35 checks, exit 0)
- Smoke: PHP built-in server against seeded DB (blank page, search filter, injection inert, array query param, and unopenable-DB generic 500 with no leaked details)
Pagination and sorting are out of scope for M4 per the candidate brief. CRUD actions and refresh (same-day UPSERT) remain future work. Update this section as milestones land.

M5 (Keyword detail) is **complete**. Completed slices: `public/keyword.php` (GET detail page with a safe 404 for missing/invalid/array/negative/zero/unknown ids and a generic 500 on unexpected failures), `src/keyword.php` (`minirank_parse_id()` strict positive-int parsing, `minirank_find_keyword()`, `minirank_position_history()` newest-first, `minirank_render_history()` escaping helper with an empty state), `src/list.php` (list phrases now link to the detail page), `public/style.css` (.history table styles), and `tests/keyword_test.php` + `tests/keyword_smoke_test.php` (HTTP smoke via the PHP built-in server; deleted-keyword id is tested separately from a never-existing id). Verified commands:
- Lint: `php -l src/keyword.php`, `php -l public/keyword.php`, `php -l src/list.php`, `php -l tests/keyword_test.php`, `php -l tests/keyword_smoke_test.php`, `php -l tests/list_test.php`
- Tests: `php tests/db_foundation_test.php` (38 checks, exit 0)
- Tests: `php tests/seed_trend_test.php` (37 checks, exit 0, no warnings, no leftover temp files)
- Tests: `php tests/list_test.php` (38 checks, exit 0)
- Tests: `php tests/keyword_test.php` (30 checks, exit 0, no leftover temp files)
- Tests: `php tests/keyword_smoke_test.php` (26 checks, exit 0; HTTP: safe 404 for missing/`abc`/`-5`/`0`/`999999`/array/deleted id, newest-first history, 30-row seeded history, empty state, escaped hostile phrase, generic 500 with no leaked details; no leftover temp files or stray processes)
CRUD actions and refresh (same-day UPSERT) remain future work. Update this section as milestones land.

## Project scope
-MiniRank tracks keywords for one configured website.
-Complete and verify M1–M8 before attempting another stretch goal.

## Stack (locked decisions)
-use PHP 8.0 compatible syntax and APIs. dont use enums readonly properties the never return type intersection types or first class callable syntax.
-use plain PHP PDO SQLite server rendered html css and vanilla js. no framework, package manager or additional dependencies.
- Separate public GET pages from POST action. Create, update and delete use Post/Redirect/Get with a 303 redirect.
-Refresh returns JSON for AJAX and must not reload the page.
- Security is non-negotiable: prepared SQL statements only, escape all output, mutations happen only via POST, and every POST requires CSRF protection.
-keep SQL in repository code rather than pages or templates.

## Data model (locked decisions)
-keyword phrases are trimmed , 1-120 characters and case insensitively unique.
-dates use 'YYYY-MM-DD' format only.
-deleting a keyword cascades to its positions. enable SQLite foreign keys on every connection.
-seed creates 30 days of demo history and is safe to run repeatedly.
- Positions are integers in the range 1–100. Lower is better.Each keyword can have one position per date, enforced by "UNIQUE(keyword_id,recorded_on)"
- Trend baseline: the newest row on or before (today minus 7 days).
-current position is the newsest recorded position.
-the 7 day baseline is the newest position recorded on or before today minus seven days.
-current below baseline is improved and current over baseline is declined. 
-Equal is stable. Missing baseline means stable with "not enough history"
- Refresh (recording current position) uses a same-day UPSERT: one row per keyword per day, insert or update.

## Repo layout

- `data/` — stores runtime SQLite files and is ignored except for `.gitkeep`. - Add tracked fixtures/config via new top-level files or an explicit ignore exception, not inside `data/`.
- `.env` / `.env.*` are gitignored; keep secrets out of the repo.
-never commit databases SQLite WAL/SHM files environment files logs credentials or API keys.


## Security
- bind all dynamic SQL values with PDO prepared statements.never concatenate request data into SQL.
- escape every dynamic HTML value at output with `htmlspecialchars`.
- escape `%`, `_`, and `\` before using user input in a `LIKE` search.
- mutations are POST-only and require CSRF protection.
- validate IDs as positive integers and return a safe 404 for missing or unknown records.
- browser errors must not expose SQL, stack traces, credentials, tokens, or local paths.
- never commit databases, SQLite WAL/SHM files, environment files, logs, credentials, or API keys.
## Workflow
-plan before editing and implement one bounded slice per session.
-Dont modify files outside the approved slice.
-verify applicable acceptance checks before claiming completion and report anything not verified.
-I own all Git writes. Agent may only inspect Git status logs  diffs and show but must not stage commit push reset or rewrite history. Propose the commands and i will run them.
- Work in small slices and verify before completion.
