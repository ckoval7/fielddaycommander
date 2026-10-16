---
name: branch-workflow
description: Use this skill for any of these tasks in FD Commander: choosing which checkout (main or the demo worktree) and which branch work belongs on; starting, committing or merging work; merging main into feature/demo-mode; starting or fixing the local Sail container; running artisan, tests or Pint; or setting up the demo worktree from scratch.
---

# Branch & Worktree Workflow

Demo mode (`feature/demo-mode`) is a long-lived branch that is **not** merged into `main`. The repo is used from two checkouts that sit side by side:

| Checkout | Branch | Used for |
|----------|--------|----------|
| `fielddaycommander/` (main checkout) | `main` and feature branches off `main` | All non-demo work |
| `fielddaycommander-demo/` (git worktree, a sibling directory) | `feature/demo-mode` | All demo work, plus the running Sail stack |

## Choosing where work goes

1. **Is the change demo-specific?** That means it touches DemoSeeder, DemoController, DemoMiddleware, demo commands or config, the demo branches inside services, or demo provisioning or teardown.
   - **Yes:** work in the demo worktree and commit directly on `feature/demo-mode`. Don't create sub-branches.
   - **No:** work in the main checkout on a new feature branch off an up-to-date `main`. Never commit directly to `main`.
2. **Demo code never moves to `main`.** Don't suggest it, even when a demo fix looks generic.
3. **A bug found while click-testing demo that isn't demo-specific** is fixed on a branch off `main`. Bring it into demo by merging `main` into `feature/demo-mode`. Don't fix it on demo.
4. **Keep each checkout on its own branch.** Don't check out `feature/demo-mode` in the main checkout, or `main` in the worktree. Git refuses this anyway, because a branch can only be checked out in one worktree.

## Bringing main into demo

After a feature branch has merged into `main`:

```bash
cd ../fielddaycommander-demo
git fetch origin && git merge origin/main
docker exec -u sail fielddaycommander-laravel.test-1 npm run build
docker exec -u sail fielddaycommander-laravel.test-1 php artisan optimize:clear
# only if the merge brought new migrations:
docker exec -u sail fielddaycommander-laravel.test-1 php artisan migrate --database=mysql --force
```

Locally, `DB_CONNECTION=demo`, so `--database=mysql` is needed to migrate the central DB (it holds `demo_sessions` and other central tables).

## Sail container

- Start Sail **only from the demo worktree**: `docker compose up -d`. The worktree's `.env` has `COMPOSE_FILE=docker-compose.sail.yml` and `COMPOSE_PROJECT_NAME=fielddaycommander`, so the container names (`fielddaycommander-laravel.test-1`, `-mysql-1`, `-redis-1`, `-scheduler-1`) and the MySQL/Redis volumes stay the same.
- The container mounts the worktree at `/var/www/html`. Nothing mounts the main checkout.
- If the app shows a 500 or old assets, check that the container mounts the worktree:

  ```bash
  docker inspect fielddaycommander-laravel.test-1 --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}'
  ```

  If it doesn't, run `docker compose down` from wherever it was started, then `docker compose up -d` from the worktree.

## Running PHP tooling

Host PHP is too old (8.3, and it lacks ext-xml), so always run PHP tooling in a container. Keep test runs targeted to a file, directory or filter, not the full suite.

The local `.env` is in demo mode, so tests need `DEMO_MODE=false` and `DB_CONNECTION=sqlite` set in the environment.

**Demo worktree** (uses the running container):

```bash
docker exec -e DEMO_MODE=false -e DB_CONNECTION=sqlite fielddaycommander-laravel.test-1 php artisan test --compact <path>
docker exec fielddaycommander-laravel.test-1 vendor/bin/pint --dirty --format agent
```

**Main checkout** (one-off container, run from the main checkout root):

```bash
docker run --rm -v "$PWD":/var/www/html -w /var/www/html -u 1000:1000 \
  -e DEMO_MODE=false -e DB_CONNECTION=sqlite \
  --entrypoint php sail-8.4/app artisan test --compact <path>

docker run --rm -v "$PWD":/var/www/html -w /var/www/html -u 1000:1000 \
  --entrypoint vendor/bin/pint sail-8.4/app --dirty --format agent
```

## Setting up the demo worktree from scratch

Run these from the main checkout, which needs a working `.env`, `vendor/` and `node_modules/`:

```bash
docker compose down                       # if Sail was running from here
git worktree add ../fielddaycommander-demo feature/demo-mode
cp -a .env vendor node_modules ../fielddaycommander-demo/
printf '\nCOMPOSE_PROJECT_NAME=fielddaycommander\n' >> ../fielddaycommander-demo/.env
cp -a storage/app/private/gallery ../fielddaycommander-demo/storage/app/private/   # uploaded files, if any
cd ../fielddaycommander-demo && docker compose up -d
```

Then run the build, cache-clear and migrate steps from "Bringing main into demo".
