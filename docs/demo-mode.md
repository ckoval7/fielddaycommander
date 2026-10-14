# Demo Mode

Demo mode provisions each visitor an isolated MySQL database on demand, letting them explore the application without affecting real data. It is controlled by the `DEMO_MODE` environment variable and should **never** be enabled in production.

On the landing page a visitor picks an **event** — ARRL Field Day or Winter Field Day — and a **role**. Both choices are submitted together, and the sandbox is seeded for that event's rulebook. See [Event Selection](#event-selection) below.

## Configuration

Set these values in `.env`:

| Variable                        | Default | Description                                      |
|---------------------------------|---------|--------------------------------------------------|
| `DEMO_MODE`                     | `false` | Enable or disable demo mode                      |
| `DEMO_TTL_HOURS`                | `24`    | Hours before a demo database is eligible for cleanup |
| `DEMO_MAX_SESSIONS`             | `25`    | Maximum concurrent demo databases                |
| `DEMO_MAX_USERS_PER_SESSION`    | `5`     | People who may share one sandbox via its invite link, including the visitor who provisioned it (`1` turns invites off) |
| `DEMO_ANALYTICS_RETENTION_DAYS` | `90`    | Days to keep analytics data before pruning       |
| `DEMO_WEATHER_LAT`              | `41.8781`  | Latitude used for the shared demo weather location |
| `DEMO_WEATHER_LON`              | `-87.6298` | Longitude used for the shared demo weather location |
| `DEMO_WEATHER_STATE`            | `IL`    | State abbreviation paired with the weather coordinates |
| `DEMO_WEATHER_CACHE_TTL`        | `30`    | Minutes to cache weather responses across demo sessions |
| `DEMO_SIMULATOR_CACHE_STORE`    | `redis` | Cache store the activity simulator uses to track its sessions |
| `DEMO_DB_DATABASE`              | `demo_base` | Database the `demo` connection points at before a visitor provisions |
| `DEMO_DB_USERNAME`              | `demo_provisioner` | MySQL account used to create and seed demo databases |
| `DEMO_DB_PASSWORD`              | —       | Password for that account                        |

`DEMO_SIMULATOR_CACHE_STORE` must name a store that lives **outside** the per-visitor demo databases (Redis in production, `array` in tests). Pointing it at the default `database` store would write the simulator's bookkeeping into whichever demo database is currently connected.

In demo mode, weather is pinned to a single shared location (not the per-session event configuration) so all active demo tenants reuse one cached NWS/Open-Meteo response. A shared cache driver (Redis, Memcached, or database) is required for cross-session visibility — `file` and `array` drivers are per-node and break this.

## Database Setup

Demo mode needs three things beyond `DEMO_MODE=true`. Provisioning fails without them, so set them up before enabling demo mode.

### 1. The default connection must be `demo`

```dotenv
DB_CONNECTION=demo
```

`DemoMiddleware` repoints the **`demo`** connection at the visitor's database on each request. It does not change the application's default connection, so models without an explicit `$connection` — `User`, `Contact`, `Event` and the rest — only reach the sandbox when `demo` *is* the default. Leaving it as `mysql` lets a visitor provision successfully and then fail at login with `There is no role named 'Event Manager' for guard 'web'`, because the role lookup runs against the main database.

Analytics models (`DemoSession`, `DemoEvent`) declare `protected $connection = 'mysql'`, so they keep writing to the main database regardless.

### 2. The provisioning account needs `CREATE DATABASE`

The `demo` connection authenticates as `DEMO_DB_USERNAME` (default `demo_provisioner`). That account creates, migrates and drops the per-visitor `demo_*` databases, so it needs privileges on the `demo_%` pattern plus `CREATE`/`DROP`:

```sql
CREATE USER 'demo_provisioner'@'%' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON `demo\_%`.* TO 'demo_provisioner'@'%';
GRANT CREATE, DROP ON *.* TO 'demo_provisioner'@'%';
FLUSH PRIVILEGES;
```

Without the grant, provisioning fails at `CREATE DATABASE` with `SQLSTATE[42000] ... 1044 Access denied`. MySQL applies new grants only to new connections, so restart PHP-FPM (or the app container) after granting.

Scope the grant to `demo_%` rather than `*.*`. `demo:cleanup` drops databases with this account, and a broader grant would let it drop the application's own.

### 3. `demo_base` must exist and be migrated

Before a visitor provisions, the `demo` connection points at `DEMO_DB_DATABASE` (default `demo_base`). Because that is now the default connection, the landing page itself queries it — if the database is missing, `/demo` returns a 500.

```bash
mysql -e "CREATE DATABASE \`demo_base\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate --database=demo --force
php artisan migrate --database=mysql --force   # analytics tables
```

### Updating a demo instance

Both migrate commands are needed on **every** update, not just the first deploy. Because `demo` is the default connection, a plain `php artisan migrate --force` migrates `demo_base` alone and reports `Nothing to migrate` while the analytics tables in the main database stay behind. A migration touching `demo_sessions` or `demo_events` is then silently skipped, and provisioning fails with `SQLSTATE[42S22] ... Unknown column`.

`deploy.sh --demo` and `update.sh` both handle this automatically, keying off `DEMO_MODE=true` in the deployed `.env`. Run the second command by hand only when migrating outside those scripts.

Note that a provisioning failure still leaves its `demo_*` database behind, and orphans count toward `DEMO_MAX_SESSIONS`. `demo:cleanup` clears them once they pass the TTL, or drop them by hand after a failed update.

## Event Selection

The landing page presents both events; the choice drives how `DemoSeeder` builds the sandbox.

| | ARRL Field Day (`FD`) | Winter Field Day (`WFD`) |
|---|---|---|
| Entry class | 4A | 4O (Outdoor) |
| GOTA station | Yes | None — the WFDA rules define no GOTA station |
| Power multiplier | 2× | None; all entrants are capped at 100 W |
| Received exchange classes | A–F | H, I, O, M |
| QSO points | `modes.points_fd` | `modes.points_wfd` |
| Awards | Bonus points, added to the score | Objectives, multiplying it |
| Shift roles seeded | 8 | 5 |
| Safety checklist | Seeded | Empty — it backs an ARRL-only bonus |

The chosen code is recorded on the `demo_sessions` analytics row as `event_type`, so usage can be broken down by rulebook. Rows predating the picker default to `FD`.

Only manually-claimed objectives are seeded for WFD. Derived ones — alternative power, band and mode counts, QRP — belong to their strategies in `app/Scoring/Bonuses/WinterFieldDay2026/` and are reconciled from the logged contacts.

`DemoSeeder` defaults to `FD`, so running it by hand still produces a Field Day sandbox:

```bash
php artisan db:seed --class=DemoSeeder --database=demo --force
```

To seed Winter Field Day, bind the event type before the seeder runs — `db:seed` accepts no custom arguments, so the choice travels through the container:

```php
app()->instance(DemoSeeder::EVENT_TYPE_KEY, 'WFD');
```

Always pass `--database=demo`. Without it the seeder writes to the default connection, which fills the shared application database and leaves the sandbox empty.

## Artisan Commands

### `demo:analytics-link`

Generate a time-limited signed URL for the demo analytics dashboard.

```bash
php artisan demo:analytics-link
php artisan demo:analytics-link --hours=48 --range=30d
php artisan demo:analytics-link --api
```

| Option       | Default | Description                                          |
|--------------|---------|------------------------------------------------------|
| `--hours=N`  | `24`    | How many hours the signed link is valid              |
| `--range=R`  | `7d`    | Date range to embed (`today`, `7d`, `30d`, `90d`)    |
| `--api`      | —       | Generate the JSON API URL instead of the dashboard   |

The analytics dashboard and API routes require a valid signed URL — they cannot be accessed directly.

### `demo:simulate-activity`

Keep each active demo sandbox moving. Designed to run on a schedule (e.g. every minute via `schedule:run`) to keep demo dashboards lively. Each invocation:

- **Rotates operators.** When an operator's shift ends and they check out, their session closes and a free on-duty operator takes the station over. Idle stations are occasionally picked back up.
- **Logs contacts.** Each simulated operating session has a ~25% chance of a new contact (~15 QSOs/hour per station), matching the rate of the seeded history.
- **Checks volunteers in and out.** Volunteers arrive from 15 minutes before a shift starts and leave from 5 minutes before it ends; anyone still pending 10 minutes past the boundary is moved along.

It only touches what was seeded: the stations, sessions and shift assignments registered when the sandbox was provisioned. Sessions the visitor starts, and shifts belonging to the persona users a visitor can sign in as, are left alone.

```bash
php artisan demo:simulate-activity
```

### `demo:cleanup`

Drop expired demo databases whose `demo_provisioned_at` timestamp exceeds the configured TTL. Also prunes analytics session records older than the retention period.

```bash
php artisan demo:cleanup
```

## Routes

| Method | URI                        | Name                        | Auth       |
|--------|----------------------------|-----------------------------|------------|
| GET    | `/demo`                    | `demo.landing`              | Public     |
| POST   | `/demo/provision`          | `demo.provision`            | Throttled  |
| POST   | `/demo/reset`              | `demo.reset`                | Throttled  |
| GET    | `/demo/join/{token}`       | `demo.join`                 | Throttled  |
| POST   | `/demo/join/{token}`       | `demo.join.store`           | Throttled  |
| POST   | `/demo/analytics/beacon`   | `demo.analytics.beacon`     | Throttled  |
| GET    | `/demo/analytics`          | `demo.analytics.dashboard`  | Signed URL |
| GET    | `/demo/analytics/api`      | `demo.analytics.api`        | Signed URL |

`POST /demo/provision` requires both a `role` (`operator`, `station_captain`, `event_manager`, `system_admin`) and an `event_type` (`FD` or `WFD`). Either one missing or unrecognised is a validation error.

### Inviting others

The demo banner's **Invite** button shows a join link (`/demo/join/{token}`) for the visitor's sandbox. Whoever opens it enters their name (and optionally a call sign), picks a role, and gets their own account in the same database, so everyone shares the data but each person's actions are attributed to them. The guest's user ID travels in the encrypted `demo_guest` cookie, which `DemoMiddleware` uses to sign them back in as themselves; the visitor who provisioned the sandbox keeps the seeded persona for their role. Each join takes a seat on the sandbox's `participant_count`; once it reaches `DEMO_MAX_USERS_PER_SESSION`, the link turns new visitors away. Pressing **Start over** gives up a seat — a guest leaving does not mark the sandbox as reset for analytics. Links stop working when the sandbox expires.
