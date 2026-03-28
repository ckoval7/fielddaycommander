# Docker Deployment

Run FD Commander with Docker Compose. The setup includes an application container (web server, WebSockets, queue worker, scheduler) and a MySQL database container.

## Quick Start

```bash
# 1. Generate .env with secure defaults (DB password, Reverb keys, etc.)
bash docker/setup.sh

# 2. Build and start
docker compose up -d

# 3. Check logs (first run will migrate and seed the database)
docker compose logs -f app
```

The setup script creates `.env` from `.env.example` and auto-generates all secrets (`DB_PASSWORD`, `REVERB_APP_KEY`, etc.). Run it again on an existing `.env` to fill in any missing secrets without overwriting existing values.

The app will be available at `http://localhost` (or whichever port you set with `APP_PORT`).

## Configuration

### Environment Variables

These variables in `.env` are used by `docker-compose.yml`:

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_PORT` | `80` | Host port mapped to the container |
| `DB_DATABASE` | `fd_commander` | MySQL database name |
| `DB_USERNAME` | `fd_commander` | MySQL user |
| `DB_PASSWORD` | *(required)* | MySQL password |
| `DB_ROOT_PASSWORD` | `rootsecret` | MySQL root password |

### Custom Port

```bash
APP_PORT=8080 docker compose up -d
```

## Architecture

The app container runs four processes via supervisord:

| Process | Command | Purpose |
|---------|---------|---------|
| Octane | `frankenphp php-cli artisan octane:frankenphp` | HTTP server (port 80) |
| Reverb | `php artisan reverb:start` | WebSocket server (port 8080, internal) |
| Queue Worker | `php artisan queue:work` | Background job processing |
| Scheduler | `php artisan schedule:run` (every 60s) | Scheduled tasks |

WebSocket connections reach Reverb through Caddy's reverse proxy at the `/app` path — only port 80 is exposed.

## First Run

On first startup, the entrypoint script automatically:

1. Waits for the database to be ready
2. Runs all migrations
3. Runs production seeders (event types, bands, modes, sections, roles, permissions, etc.)
4. Generates an `APP_KEY` if not set
5. Generates Reverb WebSocket credentials if not set
6. Caches configuration, routes, views, and events

Seeders only run once. A marker file in the persistent storage volume prevents re-seeding on subsequent starts.

## Reverse Proxy (HTTPS)

The container serves plain HTTP on port 80. For HTTPS, place a reverse proxy in front of it. The app includes `trustProxies(at: '*')` so `X-Forwarded-Proto` and `X-Forwarded-For` headers are respected automatically.

Example with Nginx:

```nginx
server {
    listen 443 ssl;
    server_name your-domain.com;

    ssl_certificate     /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # WebSocket endpoint (Reverb)
    location /app {
        proxy_pass http://127.0.0.1:80;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }

    # Everything else
    location / {
        proxy_pass http://127.0.0.1:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

When behind a TLS proxy, update your `.env`:

```env
APP_URL=https://your-domain.com
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

## Adding Redis

Uncomment the `redis` service in `docker-compose.yml`, then update `.env`:

```env
CACHE_STORE=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

## Managing Services

```bash
# View all process statuses inside the app container
docker compose exec app supervisorctl status

# Restart a specific process
docker compose exec app supervisorctl restart queue-worker

# View logs
docker compose logs -f app
docker compose logs -f mysql

# Stop everything
docker compose down

# Stop and remove volumes (destroys data)
docker compose down -v
```

## Data Persistence

Two named volumes store persistent data:

| Volume | Contents |
|--------|----------|
| `app-storage` | Uploaded files, logs, framework cache |
| `mysql-data` | Database files |

These survive `docker compose down`. Use `docker compose down -v` to remove them (destructive).

## Rebuilding

After pulling new code:

```bash
docker compose build
docker compose up -d
```

The entrypoint runs `php artisan migrate --force` on every start, so new migrations are applied automatically.
