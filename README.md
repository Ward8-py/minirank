# MiniRank

Keyword rank tracker for one configured website. PHP 8 + SQLite, plain server-rendered HTML, no dependencies.

## Requirements

- PHP 8.0+ CLI with the `pdo_sqlite` and `session` extensions.

```sh
php -v
php -r "var_export(extension_loaded('pdo_sqlite')); echo PHP_EOL; var_export(extension_loaded('session')); echo PHP_EOL;"
```

## Configuration

Edit `config.php` for the site name and URL. The database defaults to `data/minirank.sqlite` and is auto-created on first use; override the location with the `MINIRANK_DB_PATH` environment variable.

## Run it

```sh
git clone https://github.com/Ward8-py/minirank.git
cd minirank
php bin/seed.php
php -S 127.0.0.1:8000 -t public
```

Open http://localhost:8000 — six keywords with 30 days of demo history, plus search, add/edit/delete, and one-click refresh. `php bin/seed.php` is idempotent and safe to rerun.

## Run it with Docker

Requires Docker with the Compose plugin.

```sh
docker compose up -d --build
```

Open http://localhost:8000 — the container runs PHP 8.0 (the image build verifies the `pdo_sqlite` and `session` extensions) and seeds the six demo keywords on first boot. If port 8000 is already in use:

```sh
MINIRANK_PORT=8080 docker compose up -d --build
```

The SQLite database lives in a Docker named volume (`minirank-data`), not in the repo, so it survives restarts without committing a database file:

```sh
docker compose restart   # data survives
docker compose down      # stops the app; the volume and data survive
docker compose down -v   # also deletes the volume — data is lost
```

Seeding runs only when the seed marker is absent or the database is missing/empty; a failed first-boot seed is retried on the next start, and ordinary restarts never restore data you deleted. The native PHP setup (`php bin/seed.php && php -S 127.0.0.1:8000 -t public`) is unchanged.

## Tests

Each `tests/*.php` file is a standalone script that prints PASS/FAIL and exits 0 on success:

```sh
# POSIX
for f in tests/*.php; do php "$f" || exit 1; done
```

```powershell
# PowerShell
Get-ChildItem tests -Filter *.php | ForEach-Object { php $_.FullName }
```

## Troubleshooting

- `php: command not found` — PHP is not installed or not on your PATH.
- `could not find driver` — enable the `pdo_sqlite` extension in `php.ini`.
- Port 8000 already in use — pick another port in the server command.
- `Seed failed.` — make sure `data/` (or your `MINIRANK_DB_PATH`) is writable.
- Blank page / HTTP 500 — confirm PHP 8.0+ and check the server console output.