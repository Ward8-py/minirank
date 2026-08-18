# AGENTS.md

## Status
M1 (Keywords CRUD) is **complete**. Completed slices: database foundation — `src/db.php` (reusable PDO connection), `src/schema.php` (SQLite DDL), and `tests/db_foundation_test.php` (reusable acceptance checks); deterministic seed and seven-day trend — `src/seed.php` (transactional `minirank_seed()`, `INSERT OR IGNORE`, 6 demo keywords x 30 consecutive days anchored to today), `src/trend.php` (`minirank_trend()` lower-is-better: improved/declined/stable/not enough history), `bin/seed.php` (idempotent CLI, generic error output), and `tests/seed_trend_test.php` (idempotence, no-overwrite, determinism, atomicity, trend cases, CLI smoke). Verified commands:
- Lint: `php -l src/db.php`, `php -l src/schema.php`, `php -l src/seed.php`, `php -l src/trend.php`, `php -l bin/seed.php`, `php -l tests/db_foundation_test.php`, `php -l tests/seed_trend_test.php`
- Tests: `php tests/db_foundation_test.php` (38 checks, exit 0 on pass)
- Tests: `php tests/seed_trend_test.php` (37 checks, exit 0 on pass, no warnings, no leftover temp files)
- CLI: `php bin/seed.php` (exit 0; rerun is a no-op; respects `MINIRANK_DB_PATH`; failure prints only "Seed failed.")
Keyword creation is **complete**: `src/session.php` (idempotent session start with httponly + SameSite=Lax cookie, `minirank_csrf_token()`/`minirank_csrf_verify()` via `hash_equals`, `minirank_csrf_field()`, one-shot session flash set/pull/render), `src/keyword.php` (`minirank_validate_phrase()` trim + blank/oversized rejection, `minirank_keyword_exists()` NOCASE, `minirank_create_keyword()` bound insert with duplicate backstop), `src/list.php` (`minirank_render_add_form()` escaping helper posting to `actions/keyword-create.php`), `public/actions/keyword-create.php` (dedicated POST endpoint: 405 on non-POST, 403 on missing/wrong CSRF before any mutation, 303 Post/Redirect/Get back to `index.php` for success/blank/oversized/duplicate, generic 500 with no leaked details), `public/index.php` (renders flash + add form), `public/style.css` (.add-form and .alert styles), and `tests/create_test.php` + `tests/create_smoke_test.php` (HTTP smoke with session cookie + CSRF token extraction). Verified commands:
- Lint: `php -l src/session.php`, `php -l src/keyword.php`, `php -l src/list.php`, `php -l public/index.php`, `php -l public/actions/keyword-create.php`, `php -l tests/create_test.php`, `php -l tests/create_smoke_test.php`
- Tests: `php tests/create_test.php` (39 checks, exit 0, no warnings, no leftover temp files)
- Tests: `php tests/create_smoke_test.php` (41 checks, exit 0; HTTP: add form on list page, 403 on missing/wrong CSRF with no mutation, 405 on GET, 303 PRG for valid/blank/oversized/duplicate/hostile creates, error pages link back to the list page with no relative 404, escaped hostile phrase, one-shot flash, generic 500 with no leaked details; no leftover temp files or stray processes)
Keyword edit and delete are **complete**: `src/keyword.php` (`minirank_keyword_exists()` now takes an optional `$excludeId` so an update may keep its own phrase case-insensitively, `minirank_update_keyword()` find → validate → duplicate-excluding-self → bound UPDATE returning ok/id/error/not_found, `minirank_delete_keyword()` bound DELETE returning `rowCount() > 0`, positions cascade via FK), `src/list.php` (`minirank_render_edit_form()` prefilled escaped phrase posting to `actions/keyword-update.php`, `minirank_render_delete_form()` posting to `actions/keyword-delete.php`), `public/keyword-edit.php` (GET edit page with a safe 404 for missing/invalid/array/negative/zero/unknown/deleted ids and a generic 500), `public/actions/keyword-update.php` and `public/actions/keyword-delete.php` (dedicated POST endpoints: 405 on non-POST, 403 on missing/wrong CSRF before any mutation, safe 404 for invalid/missing/array/unknown/deleted ids with no mutation, 303 PRG for success/blank/oversized/duplicate, generic 500 with no leaked details), `public/keyword.php` (renders flash, Edit link, and delete form), `public/style.css` (.actions, .edit-link, .delete-form, .btn-danger), and `tests/edit_delete_test.php` + `tests/edit_delete_smoke_test.php` (only the deleted keyword's history disappears, other keywords and seeded history untouched, global orphan position count stays 0). Verified commands:
- Lint: `php -l src/keyword.php`, `php -l src/list.php`, `php -l public/keyword.php`, `php -l public/keyword-edit.php`, `php -l public/actions/keyword-update.php`, `php -l public/actions/keyword-delete.php`, `php -l tests/edit_delete_test.php`, `php -l tests/edit_delete_smoke_test.php`
- Tests: `php tests/edit_delete_test.php` (45 checks, exit 0, no warnings, no leftover temp files)
- Tests: `php tests/edit_delete_smoke_test.php` (112 checks, exit 0; HTTP: edit page 200 + prefilled escaped phrase + safe 404s, 403 missing/wrong CSRF with no mutation, 405 on GET, safe 404 for invalid/missing/array/unknown/deleted ids on both POST endpoints with no mutation, 303 PRG for valid updates/deletes and blank/oversized/duplicate updates, keeping the current phrase or changing only its case allowed, escaped hostile phrase, cascade removes only the deleted keyword's positions with orphan count 0 and survivors untouched, deleted id 404 on detail and edit pages, generic 500 with no leaked details; no leftover temp files or stray processes)
M1 is complete. Refresh (same-day UPSERT) is M3 and remains future work. Update this section as milestones land.

M4 (Keyword list & search) is **complete**. Completed slices: `public/index.php` (GET list + case-insensitive search page with escaped output and generic 500 on unexpected failures), `config.php` (site name/URL for the one configured website), `src/list.php` (`minirank_search_keywords()` with single-query current+baseline via correlated subqueries, `%`/`_`/`\` LIKE escaping, `minirank_render_search()`/`minirank_render_list()` escaping helpers), `src/trend.php` (extracted pure `minirank_trend_from_positions()`, reused by `minirank_trend()`), `public/style.css` (minimal), and `tests/list_test.php`. Verified commands:
- Lint: `php -l src/trend.php`, `php -l src/list.php`, `php -l config.php`, `php -l public/index.php`, `php -l tests/list_test.php`
- Tests: `php tests/db_foundation_test.php` (38 checks, exit 0)
- Tests: `php tests/seed_trend_test.php` (37 checks, exit 0, no warnings, no leftover temp files)
- Tests: `php tests/list_test.php` (35 checks, exit 0)
- Smoke: PHP built-in server against seeded DB (blank page, search filter, injection inert, array query param, and unopenable-DB generic 500 with no leaked details)
Pagination and sorting are out of scope for M4 per the candidate brief. Refresh (same-day UPSERT) is M3 and remains future work. Update this section as milestones land.

M5 (Keyword detail) is **complete**. Completed slices: `public/keyword.php` (GET detail page with a safe 404 for missing/invalid/array/negative/zero/unknown ids and a generic 500 on unexpected failures), `src/keyword.php` (`minirank_parse_id()` strict positive-int parsing, `minirank_find_keyword()`, `minirank_position_history()` newest-first, `minirank_render_history()` escaping helper with an empty state), `src/list.php` (list phrases now link to the detail page), `public/style.css` (.history table styles), and `tests/keyword_test.php` + `tests/keyword_smoke_test.php` (HTTP smoke via the PHP built-in server; deleted-keyword id is tested separately from a never-existing id). Verified commands:
- Lint: `php -l src/keyword.php`, `php -l public/keyword.php`, `php -l src/list.php`, `php -l tests/keyword_test.php`, `php -l tests/keyword_smoke_test.php`, `php -l tests/list_test.php`
- Tests: `php tests/db_foundation_test.php` (38 checks, exit 0)
- Tests: `php tests/seed_trend_test.php` (37 checks, exit 0, no warnings, no leftover temp files)
- Tests: `php tests/list_test.php` (38 checks, exit 0)
- Tests: `php tests/keyword_test.php` (30 checks, exit 0, no leftover temp files)
- Tests: `php tests/keyword_smoke_test.php` (26 checks, exit 0; HTTP: safe 404 for missing/`abc`/`-5`/`0`/`999999`/array/deleted id, newest-first history, 30-row seeded history, empty state, escaped hostile phrase, generic 500 with no leaked details; no leftover temp files or stray processes)
Refresh (same-day UPSERT) is M3 and is complete — see the M3 section below.

M3 (Keyword refresh) is **complete**. Completed slices: `src/refresh.php` (`minirank_drift_position()` pure 1..100 clamp for the random walk, `minirank_refresh()` transactional same-day UPSERT via `INSERT ... ON CONFLICT (keyword_id, recorded_on) DO UPDATE`; each keyword's next position drifts from today's existing value, else the newest prior position, else a fresh `mt_rand(1, 100)` start, by `mt_rand(-5, 5)` with zero movement and boundary clamping valid; returns `['refreshed' => N, 'keywords' => ...]` reusing `minirank_search_keywords()`), `public/actions/refresh.php` (dedicated POST JSON endpoint: 405 with `Allow: POST`, 403 on missing/wrong CSRF before any mutation, 200 JSON `{ok,refreshed,keywords}` encoded with `JSON_HEX_*`/`JSON_INVALID_UTF8_SUBSTITUTE` and no Location header, generic 500 JSON with no leaked details), `public/js/refresh.js` (vanilla fetch POST; table cells updated only on full success and exactly matching the JSON via `textContent`; generic message on network/invalid-JSON/error responses with no DOM updates on failure; button/status lifecycle), `src/list.php` (`minirank_render_refresh_control()` escaping helper; list rows carry `data-keyword-id` and a stable `trend` class for in-place updates), `public/index.php` (renders the refresh control and loads `js/refresh.js` with `defer`), `public/style.css` (.refresh-control), and `tests/refresh_test.php` + `tests/refresh_smoke_test.php`. Verified commands:
- Lint: `php -l src/refresh.php`, `php -l src/list.php`, `php -l public/index.php`, `php -l public/actions/refresh.php`, `php -l tests/refresh_test.php`, `php -l tests/refresh_smoke_test.php`
- Tests: `php tests/refresh_test.php` (34 checks, exit 0, no warnings, no leftover temp files)
- Tests: `php tests/refresh_smoke_test.php` (35 checks, exit 0; HTTP: refresh control on the list page, 405/403/500 all JSON with safe messages, 200 JSON with no Location header, current positions within 1..100, no-history keyword gets a current position with not enough history, repeated refresh keeps one today row per keyword, hostile phrase absent raw from the JSON, unopenable-DB generic 500; no leftover temp files or stray processes)
- Browser: real-browser no-reload verification is a manual step (single POST with no document reload, cells update in place and match the JSON response, failure leaves cells untouched)

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
