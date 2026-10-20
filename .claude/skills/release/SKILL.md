---
name: release
description: Use this skill to cut an FD Commander release. Triggers include a version bump, a release, tagging (vYY.MM.N), or "ship it". The release runs a full Sonar scan until it is clean, a Trivy scan, package updates, and only then the version bump and tag. Never bump or tag without completing the earlier steps.
---

# Release

A release always runs these steps in this order. Don't skip or reorder them, and don't bump the version early.

1. Full Sonar scan, repeated until it is clean
2. Trivy vulnerability scan, repeated until it is clean
3. Package updates
4. Version bump, merge to `dev` and `main`, and tag
5. Bring the release into demo

Do all release work in the **main checkout**, on a release branch off `dev`. The `branch-workflow` skill explains the checkout layout and how to run PHP tooling in the main checkout. Keep test runs targeted, never the full suite.

## 0. Prepare

```bash
git status --short                 # must be clean
git switch dev && git pull --ff-only
```

If `VERSION` on `dev` is already a number with no tag, the `promote-dev` skill has bumped it. Release that version: still run steps 1–3 on the release branch, but skip the bump commit in step 4 and go straight to the merges and tag.

Otherwise, work out the next version. The scheme is `vYY.MM.N`, where `N` counts releases within the month. If `VERSION` has the current year and month, increment `N`. Otherwise use `YY.MM.1` for the current month. Confirm the version with the user, then create the branch:

```bash
git switch -c release/vYY.MM.N
```

## 1. Full Sonar scan (iterate until clean)

The `scripts/sonar-local.sh` script runs a throwaway local SonarQube server that uses SonarCloud's own **"Sonar way comprehensive"** quality profiles. The `up` command imports them, so local results match SonarCloud's rules. Run the script from the main checkout:

```bash
S=.claude/skills/release/scripts/sonar-local.sh
$S up        # ~1 min the first time; reuses the server if it's already running
$S scan      # scans HEAD plus uncommitted changes; prints issues, hotspots and extra checks
$S cloud     # SonarCloud's gate and open issues for main, for comparison
$S pr <n>    # waits for SonarCloud's analysis of a PR, then prints its gate and issues
```

Repeat this loop until the scan reports **0 issues and 0 hotspots**, the "SonarCloud-only rule checks" section prints `none`, and `cloud` shows no open issues:

1. Fix every reported issue in the code. Severity doesn't matter: SonarCloud's Reliability gate fails on any minor bug.
2. For a finding that really is intentional, don't mark it Won't fix yourself. Document the reasoning as an entry in `sonar-project.properties` and ask the user. SonarCloud closes these through `issues/do_transition` with `transition=wontfix` and a comment.
   - The local scanner applies the `sonar.issue.ignore.multicriteria` entries in `sonar-project.properties`, but SonarCloud ignores them. If an issue appears locally while SonarCloud already has it Accepted or Won't fix, the properties file is missing its entry. Add the entry so the local scan matches. Don't change the code.
3. For each fix, run Pint and the targeted tests for the code you changed.
4. Run `$S scan` again.

Commit the fixes on the release branch as `chore(quality): resolve Sonar findings`, or as more specific commits.

Some SonarCloud rules aren't implemented by the local analyzers, and the script greps for the ones that have bitten this project before:

- `css:S9423`: `!important`
- `shell:S6506`: `curl -L` piped to a shell without `--proto '=https' --tlsv1.2`
- `Web:S6819`: `role="dialog"`. Use a native `<dialog class="modal">` with `showModal()`.

Also review changed closures for unused parameters by hand (`php:S1172`). For mouse-only handlers (`Web:MouseEventWithoutKeyboardEquivalentCheck`), use a native `<button>`.

## 2. Trivy (iterate until clean)

```bash
docker pull -q aquasec/trivy:latest
docker run --rm -v "$PWD":/project:ro -v fielddaycommander_sail-trivy-cache:/root/.cache/trivy \
  aquasec/trivy:latest fs --scanners vuln \
  --skip-files /project/vendor/mockery/mockery/docs/requirements.txt \
  --skip-dirs /project/storage --skip-dirs /project/node_modules --skip-dirs /project/vendor \
  /project
```

This scans `composer.lock` and `package-lock.json`. The Mockery docs file is excluded on purpose (see the comment in `docker-compose.sail.yml`).

To fix a vulnerability, upgrade the affected package to its patched version. Use `composer update vendor/pkg --with-dependencies`; for npm, follow the lock-file rules in step 3. Re-scan until every target shows `0`. Commit as `fix(deps): upgrade <pkg> to <ver> (<CVE or GHSA id>)`.

## 3. Update packages

Run Composer in a one-off container from the main checkout (host PHP is too old):

```bash
run() { docker run --rm -v "$PWD":/var/www/html -w /var/www/html -u 1000:1000 \
  -e DEMO_MODE=false -e DB_CONNECTION=sqlite --entrypoint "$1" sail-8.4/app "${@:2}"; }
run composer outdated --direct      # list available updates first
run composer update                 # updates within the existing constraints only
```

For npm, the lock file must pass `npm ci` with npm 10, because deploys use npm 10:

```bash
npx -y npm@10.8.2 update || npx -y npm@11 update     # npm 10 crashes with "edgesOut"? Use 11.
mkdir -p ~/.cache/fdc-npmci && cp package.json package-lock.json ~/.cache/fdc-npmci/
npx -y npm@10.8.2 ci --ignore-scripts --prefix ~/.cache/fdc-npmci
npx -y npm@10.9.4 ci --ignore-scripts --prefix ~/.cache/fdc-npmci
npm run build
```

Rules for this step:

- **Major version bumps** change the dependencies, so the user must approve them. List them with what would change, ask, and only bump the ones they approve.
- **Testing:** run the targeted tests for the areas the updated packages touch. Livewire and maryUI updates need tests that exercise components. Pest and PHPUnit updates need one test directory. Ask before running anything broader.
- **Re-scan:** run Trivy again after updating. Run `$S scan` again too if updates changed any code, such as published vendor assets or config.
- **Commit:** use `chore(deps): update composer and npm packages`.

## 4. Version bump, merge, and tag

The bump commit changes exactly three files:

- `VERSION`: the new version, without the `v`
- `charts/fd-commander/Chart.yaml`: set `appVersion: "YY.MM.N"`. Leave the chart's own `version: 0.1.0` unchanged.
- `CHANGELOG.md`: rename `## Unreleased` to `## YY.MM.N - YYYY-MM-DD` and add a fresh, empty `## Unreleased` above it.

Before the bump, check the Unreleased section against `git log --no-merges <last tag>..HEAD`. Every change a user or self-hoster would notice needs an entry, and the release branch's own fixes and package updates count too. Follow the rules in the Changelog section of `CONTRIBUTING.md`: fixes are written as the symptom and its conditions, and nothing goes in that nobody would notice. Show the user the finished section before committing. If it's empty, ask whether there's anything to release.

```bash
git commit -am "chore(release): vYY.MM.N"
```

Then:

**Never push and never offer to push.** The user pushes branches, `dev`, `main` and tags themselves, when they're ready.

1. Fast-forward `dev` to the release branch: `git switch dev && git merge --ff-only release/vYY.MM.N`, then delete the release branch. Nothing else merges into `dev` during a release, so the scans cover exactly what ships. If the fast-forward fails, stop and tell the user.
2. Fast-forward `main` to `dev`: `git switch main && git merge --ff-only dev`. `main` never has commits of its own, so this always fast-forwards. If it doesn't, stop and tell the user.
3. Tell the user that `dev` and `main` are ready for them to push, and stop there.
4. Once the user says they've pushed, wait for SonarCloud's automatic analysis of the new `origin/main` HEAD. `$S cloud` prints the revision of the last analysis. It must match `origin/main` and show gate `OK` with 0 open issues. If it doesn't, return to step 1 of the release.
5. Create the annotated tag on `main`'s HEAD, locally only. The user pushes it.

   ```bash
   git tag -a vYY.MM.N -m vYY.MM.N
   ```

## 5. Bring the release into demo, then clean up

Merge `dev` into `feature/demo-mode` in the demo worktree, then rebuild, following the `branch-workflow` skill. Afterwards, remove the scan server:

```bash
.claude/skills/release/scripts/sonar-local.sh down
```
