# Workblocks

A mobile-first planner for flexible work blocks. React + TypeScript + Ionic, Laravel 13 / PHP 8.4, SQLite. The frontend uses an HTTP API; Capacitor packaging can be added later. Native app builds and offline synchronization are not included in this release.

## Local development (DDEV)

Requirements: Docker Desktop, DDEV, Node.js 22+.

```sh
cd ~/Sites/workblocks
ddev start
cp backend/.env.example backend/.env # first setup only; never overwrite an existing .env
ddev exec php artisan key:generate
# In backend/.env: set DB_CONNECTION=sqlite, DB_DATABASE=/var/www/html/backend/database/database.sqlite,
# SESSION_DRIVER=file, CACHE_STORE=file, WORKBLOCKS_PASSWORD=<at least 12 characters>.
ddev exec php artisan migrate --force
npm ci --prefix frontend
npm run dev --prefix frontend -- --port 8114 --strictPort
```

Development UI: http://localhost:8114/app/; backend: http://localhost:8104; DDEV HTTPS: https://workblocks.ddev.site/app/. Set your local password through `WORKBLOCKS_PASSWORD` in `backend/.env`; production requires its own password.

For a compiled frontend served directly by DDEV:

```sh
npm run build --prefix frontend
node scripts/install-frontend.mjs
```

## Import contract

`contracts/plan-v1.schema.json` is the mandatory JSON Schema. `examples/rani-plan.json` contains the supplied 24-hour partial week and 45-hour full week.

Import through **Plan imports**, review the replacement summary, then confirm. Alternatively:

```sh
ddev exec php artisan plan:import /var/www/html/examples/rani-plan.json --replace
```

An import replaces priorities and the standing plan, removes ALL unfinished work (including carryover), and imports exactly the supplied weeks. Missing slots in supplied weeks remain empty. Completed blocks keep their original priority names and detach from the new priority IDs. Explicitly create other weeks from the standing plan. Notes are descriptive, not executable scheduling rules. The server additionally checks priority references, unique ranks/IDs/positions, real dates, Monday week starts, block-week membership, effective date and IANA timezone.

All replacement writes are transactional. A failure rolls back the reset. SQLite backups are created BEFORE imports/restores; if backup fails, replacement does not run. HTTP writes and CLI imports use a shared file lock. Stale block edits are rejected using revision numbers.

## Persistence and recovery

SQLite lives at `backend/database/database.sqlite` locally, and `/data/workblocks.sqlite` in production's named Docker volume. Backups are in `backend/storage/app/backups` locally, `/data/backups` in production. Import history stores the full previous planner state and supports restoring the state immediately before an import. Restore replaces all planner state, including completed work; it first creates a physical database backup. Export workspace snapshot produces an additional JSON copy for inspection/archival; this JSON export is not a plan import file.

For full disaster recovery, stop writes, replace the SQLite file from a physical backup, remove stale WAL/SHM sidecars only while the application is stopped, then restart. Keep copies of database backups off the droplet. A Docker rebuild does not delete the named database volume; `docker compose down -v` DOES.

## Checks

```sh
ddev exec php artisan test
npm run build --prefix frontend
```

Feature tests cover authentication requirements, schema and cross-reference validation, exact partial weeks, destructive import/history, atomic rollback, minute conservation, partial completion, stale revisions, restore, new week generation, file database reconnection and physical backup contents.

## DigitalOcean deployment

DDEV is used for development. Production uses the included Docker Compose and Caddy/PHP-FPM setup:

```sh
cp .env.example .env
# Set a unique WORKBLOCKS_PASSWORD and APP_KEY (base64: followed by 32 random bytes encoded as base64).
# APP_KEY can be generated with: ddev exec php artisan key:generate --show
# Store this key securely and preserve it across deployments.
docker compose up -d --build
```

The default web listener is localhost:8088. For deployment with your existing reverse proxy, point it at that listener. For direct domain hosting, replace Caddyfile's `:80` with your domain and publish web ports 80 and 443; set APP_URL to the HTTPS origin and SESSION_SECURE_COOKIE=true. No droplet deployment has been performed.

## Scope

Included: day/week views, explicit move controls, extra and weekend blocks, carryover, splits and partial completion, standing-plan generation, validated replacement imports, completed history, password session sign-in, backups and import recovery.

Not yet included: automatic AI scheduling, native iOS/Android packaging, offline editing/sync, per-user accounts, automatic off-server backups. The external service produces the schedule JSON. Plan priorities and the standing template are maintained by importing that JSON.

## Shared ~/Sites workflow

After setup, use `~/Sites/dev.sh start workblocks`, `status workblocks`, `restart workblocks`, `stop workblocks`, `logs workblocks backend`, or `logs workblocks frontend`. The backend is managed by DDEV; stopping it never sweeps/kills the Docker Desktop process. The frontend runs detached on 8114. Optional friendly hostnames are configured in `~/Sites/.dev/Caddyfile`; activating them requires your usual hosts setup and proxy reload. The existing shared proxy was not restarted.
