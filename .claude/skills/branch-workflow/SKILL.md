---
name: branch-workflow
description: Use this skill for any of these tasks in FD Commander: choosing which checkout (main or the demo worktree) and which branch work belongs on; starting, committing or merging work; merging feature branches into dev or dev into feature/demo-mode; the GitHub branch protection rulesets; starting or fixing the local Sail container; running artisan, tests or Pint; or setting up the demo worktree from scratch.
---

# Branch & Worktree Workflow

Work flows **feature branch → `dev` → `main`**. `CONTRIBUTING.md` is the public version of these rules.

| Branch | Holds | Only merges in from |
|--------|-------|---------------------|
| `main` (protected) | The latest release; tags live here | `dev`, fast-forward only, at release time |
| `dev` (protected) | Finished work for the next release | Feature branches |
| `feature/demo-mode` | `dev` plus demo-only code; deployed as the demo site | `dev`, plus demo-only commits |

The repo is used from two checkouts that sit side by side:

| Checkout | Branch | Used for |
|----------|--------|----------|
| `fielddaycommander/` (main checkout) | `dev`, `main`, and feature branches off `dev` | All non-demo work |
| `fielddaycommander-demo/` (git worktree, a sibling directory) | `feature/demo-mode` | All demo work, plus the running Sail stack |

## Choosing where work goes

1. **Is the change demo-specific?** That means it touches DemoSeeder, DemoController, DemoMiddleware, demo commands or config, the demo branches inside services, or demo provisioning or teardown.
   - **Yes:** work in the demo worktree and commit directly on `feature/demo-mode`. Don't create sub-branches.
   - **No:** work in the main checkout on a new feature branch off an up-to-date `dev`. Never commit directly to `dev` or `main`.
2. **Demo code never moves to `dev` or `main`.** Don't suggest it, even when a demo fix looks generic.
3. **A bug found while click-testing demo that isn't demo-specific** is fixed on a branch off `dev`. Bring it into demo by merging `dev` into `feature/demo-mode`. Don't fix it on demo.
4. **Never merge anything but `dev` into `main`,** and only as part of a release (see the `release` skill). Never merge `main` or `feature/demo-mode` into `dev`.
5. **Never push and never offer to push.** Commits, merges and tags stay local, and the user pushes when they're ready. The user's pushes to `main` and `dev` bypass the PR rule as repo admin, and the GitHub rulesets block force-pushes, so never rewrite either branch's history.
6. **Keep each checkout on its own branch.** Don't check out `feature/demo-mode` in the main checkout, or `dev`/`main` in the worktree. Git refuses this anyway, because a branch can only be checked out in one worktree.

## Merging a feature branch into dev

Count the branch's commits first. More than one: squash. Exactly one: a regular merge.

```bash
git switch dev
git merge --squash fix/thing && git commit      # multi-commit branch; write one summary commit
git merge --no-ff fix/thing                     # single-commit branch
git branch -D fix/thing                         # -D is needed after a squash
```

Then bring `dev` into demo, below.

## Bringing dev into demo

After a feature branch has merged into `dev`:

```bash
cd ../fielddaycommander-demo
git merge dev
docker exec -u sail fielddaycommander-laravel.test-1 npm run build
docker exec -u sail fielddaycommander-laravel.test-1 php artisan optimize:clear
# only if the merge brought new migrations:
docker exec -u sail fielddaycommander-laravel.test-1 php artisan migrate --database=mysql --force
```

Use a regular merge, never a squash, or later merges from `dev` will conflict. Locally, `DB_CONNECTION=demo`, so `--database=mysql` is needed to migrate the central DB (it holds `demo_sessions` and other central tables).

## GitHub protection

`.github/rulesets/main.json` and `dev.json` define the rulesets: no deletion, no force-push, changes by PR, and two checks must pass: `source-branch` from `.github/workflows/branch-policy.yml`, and `SonarCloud Code Analysis` (the SonarQube Cloud quality gate from automatic analysis, pinned to the app's integration id 12526 so nothing else can post a passing check under that name). Repo admins bypass them.

`main` stays the default branch so visitors see the release. The `source-branch` job runs on `pull_request_target`, so it uses the base branch's copy of the workflow: it retargets feature-branch PRs from `main` to `dev`, and a change to it only takes effect for PRs into `main` once `main` has it. Never add a checkout of PR code to that workflow, since its token can edit PRs. To create or refresh them (the user runs this; it changes repo settings):

```bash
for b in main dev; do gh api -X POST repos/ckoval7/fielddaycommander/rulesets --input .github/rulesets/$b.json; done
```

To update an existing ruleset, look up its id with `gh api repos/ckoval7/fielddaycommander/rulesets` and `PUT` to `.../rulesets/<id>` instead.

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

Then run the build, cache-clear and migrate steps from "Bringing dev into demo".
