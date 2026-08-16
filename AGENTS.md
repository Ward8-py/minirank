# AGENTS.md

## Status
The repository currently contains its initial documentation. No application seed test or project lint commands exist yet. do not invent commands. update this section when verified commands are implemented.

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
