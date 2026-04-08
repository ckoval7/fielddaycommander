# Demo Mode

Demo mode provisions each visitor an isolated MySQL database on demand, letting them explore the application without affecting real data. It is controlled by the `DEMO_MODE` environment variable and should **never** be enabled in production.

## Configuration

Set these values in `.env`:

| Variable                        | Default | Description                                      |
|---------------------------------|---------|--------------------------------------------------|
| `DEMO_MODE`                     | `false` | Enable or disable demo mode                      |
| `DEMO_TTL_HOURS`                | `24`    | Hours before a demo database is eligible for cleanup |
| `DEMO_MAX_SESSIONS`             | `25`    | Maximum concurrent demo databases                |
| `DEMO_ANALYTICS_RETENTION_DAYS` | `90`    | Days to keep analytics data before pruning       |

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

Log simulated contacts to all active demo sessions. Each active operating session has a ~40% chance of receiving a new contact per invocation. Designed to run on a schedule (e.g. every minute via `schedule:run`) to keep demo dashboards lively.

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
| POST   | `/demo/analytics/beacon`   | `demo.analytics.beacon`     | Throttled  |
| GET    | `/demo/analytics`          | `demo.analytics.dashboard`  | Signed URL |
| GET    | `/demo/analytics/api`      | `demo.analytics.api`        | Signed URL |
