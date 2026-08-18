# AGENTS.md

## Status
All eight milestones (M1–M8) are **implemented**. Four stretch goals are **implemented**; three are **not implemented**.

- **Implemented — milestones:** DB foundation + deterministic seed with 7-day trend (M2), keyword CRUD with CSRF / 303-PRG / safe 404s (M1), list + search (M4), detail + history table (M5), AJAX refresh via same-day UPSERT (M3), README + five-minute setup (M7), responsive at phone width (M8), security built into every slice (M6).
- **Implemented — stretch goals:** S1 keyword history line chart (hand-rolled inline SVG), S4 movement filtering (improved/declined), S5 CSV export with formula-injection guard, S7 `docker compose up` with a persisted SQLite volume, S8 this AGENTS.md.
- **Not implemented — stretch goals:** S2 multiple projects/websites, S3 user accounts (register/log in/log out), S6 PHPUnit tests (the project's tests are standalone `tests/*.php` scripts instead).

**Verified commands:**
- Lint: `php -l` on every PHP file (`src/`, `public/`, `public/actions/`, `bin/`, `config.php`, `tests/`) — e.g. `php -l src/db.php`; PowerShell: `Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }`
- Unit suites: `php tests/db_foundation_test.php` (38), `php tests/seed_trend_test.php` (37), `php tests/list_test.php` (38), `php tests/keyword_test.php` (30), `php tests/chart_test.php` (27), `php tests/create_test.php` (39), `php tests/edit_delete_test.php` (45), `php tests/refresh_test.php` (34), `php tests/filter_test.php` (37), `php tests/export_test.php` (31)
- HTTP smoke suites: `php tests/create_smoke_test.php` (41), `php tests/edit_delete_smoke_test.php` (112), `php tests/keyword_smoke_test.php` (37), `php tests/refresh_smoke_test.php` (35), `php tests/filter_smoke_test.php` (37), `php tests/export_smoke_test.php` (31)
- Docker: `php tests/docker_smoke_test.php` (22 checks; isolated compose project: build → start → persistence across restart → tear-down)
- All 17 suites = 671 checks, all exit 0, no warnings, no leftover temp files or stray processes; responsive + refresh-no-reload verified in headless Chrome.

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

```
public/                      # web root — serve with: php -S localhost:8000 -t public
  index.php                  # GET list + search + movement filter + refresh control + add form
  keyword.php                # GET detail: history table, SVG chart, Export CSV link, delete form
  keyword-edit.php           # GET edit form (safe 404 for malformed/unknown ids)
  export.php                 # GET read-only CSV download (safe 404s, no mutation)
  style.css                  # all styles, incl. the M8 responsive rules
  js/refresh.js              # vanilla fetch AJAX refresh; rebuilds #results from JSON
  actions/                   # POST-only state changes — 405 on non-POST, CSRF required
    keyword-create.php       #   303 PRG back to list
    keyword-update.php       #   303 PRG back to detail
    keyword-delete.php       #   303 PRG back to list
    refresh.php              #   JSON endpoint for AJAX (no reload)
src/                         # library + helpers; SQL lives here, never in pages/templates
  db.php                     #   PDO connection + PRAGMA foreign_keys = ON per connection
  schema.php                 #   idempotent DDL: UNIQUE(keyword_id,recorded_on), CHECK 1–100, cascade
  seed.php                   #   transactional, idempotent 6 keywords × 30 days
  trend.php                  #   lower-is-better 7-day trend (improved/declined/stable)
  keyword.php                #   parse_id, find, validate, create/update/delete, history, chart points
  list.php                   #   search + movement filter + escaping render helpers
  session.php                #   session, CSRF token/verify/field, one-shot flash
  refresh.php                #   same-day UPSERT + random-walk drift
  export.php                 #   CSV quoting, formula-injection neutralization, filename
bin/
  seed.php                   # CLI seed — php bin/seed.php (idempotent, respects MINIRANK_DB_PATH)
tests/                       # 17 standalone PHP suites (671 checks), print PASS/FAIL, exit 0 on pass
config.php                   # the one configured website (site name + URL)
Dockerfile                   # php:8.0-cli with build-time pdo_sqlite/session checks
docker-compose.yml           # app service, port 8000 (MINIRANK_PORT override), volume minirank-data
docker/entrypoint.sh         # seed-once marker, then exec php -S 0.0.0.0:8000 -t public
.dockerignore / .gitignore
data/                        # runtime SQLite files; ignored except for .gitkeep
README.md / AGENTS.md / process.html / opencode.json
```

- `data/` stores runtime SQLite files and is ignored except for `.gitkeep`; add tracked fixtures/config via new top-level files or an explicit ignore exception, never inside `data/`.
- `.env` / `.env.*` are gitignored; keep secrets out of the repo.
- Never commit databases, SQLite WAL/SHM files, environment files, logs, credentials, or API keys.


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
