---
name: release
description: Use this skill to release Field Day Commander or to get dev ready for main. Triggers include a version bump, a release, tagging (vYY.MM.N), "ship it", "promote dev", "PR dev to main", "scan dev and open a PR into main", or a request to run Sonar on dev and watch SonarCloud on the PR. It runs a local Sonar scan and Trivy until clean, updates packages, opens the "Release vYY.MM.N" PR from dev into main and fixes what SonarCloud reports on it, bumps the version, and then fast-forwards main and tags. Never bump or tag without completing the earlier steps.
---

# Release

One flow takes `dev` from "finished work" to a tagged release on `main`. Run the steps in order. Don't skip or reorder them, and don't bump the version early.

1. Local Sonar scan, fixed until clean
2. Trivy, fixed until clean
3. Package updates
4. Push `dev` and demo, open the `Release vYY.MM.N` PR
5. SonarCloud on the PR, fixed until clean
6. Version bump
7. Ship: fast-forward `main`, SonarCloud on `main`, tag
8. Demo and clean up

**Where to stop:** "promote dev", "PR dev to main" and similar requests stop after step 6, with the PR green and carrying the new version. A request to release, tag or ship runs all the way through. If a promote run already happened (`VERSION` on `dev` is a number with no tag, and the PR is open), a release picks up at step 7, after checking that `dev` hasn't moved since: if it has, re-run steps 1–2 and 5 on the new commits.

Work in the **main checkout**. The `branch-workflow` skill covers the checkouts, merge rules and how to run PHP tooling. Keep test runs targeted, never the full suite.

**Pushing:** invoking this skill is the user's request to push `dev` and `feature/demo-mode`, in steps 4, 5 and 6. Push `main` (step 7) and the tag only when the user asks for it in this session. Never force-push.

```bash
S=.claude/skills/release/scripts/sonar-local.sh
```

## 0. Prepare

```bash
git status --short                                   # must be clean
git switch dev && git pull --ff-only
git -C ../fielddaycommander-demo status --short      # must be clean
git fetch origin && git merge-base --is-ancestor origin/main dev && echo "main can fast-forward"
```

If either checkout has uncommitted changes, stop and ask.

If `main` can't fast-forward, it has a commit `dev` lacks, usually a merge commit left by GitHub's merge button. Check that it changes nothing (`git diff <commit>^2 <commit>` is empty, and its second parent is on `dev`). Then ask the user before merging `origin/main` into `dev` with `--no-ff`. That's the only time `main` merges into `dev`. If it changes files, stop and tell the user.

Work out the version. The scheme is `vYY.MM.N`, where `N` counts releases within the month. If `VERSION` has the current year and month, increment `N`. Otherwise use `YY.MM.1` for the current month. Check `git tag` so you don't reuse a tagged number.

Fixes in steps 1–3 and 5 go on short branches off `dev` (`fix/sonar-<topic>`, `fix/deps-<pkg>`, `chore/deps-update`). Merge each into `dev` (`--no-ff` for one commit, squash for more), delete it, and bring `dev` into demo as the `branch-workflow` skill describes. Run `npm run build` there only if assets changed, `view:clear` or `optimize:clear` for view or config changes, and migrations only if the merge brought new ones.

## 1. Local Sonar scan (iterate until clean)

`sonar-local.sh` runs a throwaway local SonarQube server with SonarCloud's own **"Sonar way comprehensive"** profiles, so local results match SonarCloud's rules:

```bash
$S up        # ~1 min the first time; reuses the server if it's already running
$S scan      # scans HEAD plus uncommitted changes; prints issues, hotspots and gap checks
$S cloud     # SonarCloud's gate and open issues for main
$S pr <n>    # waits for SonarCloud's analysis of a PR, then prints its gate and issues
```

Repeat until the scan shows **0 issues and 0 hotspots** and the "SonarCloud-only rule checks" print `none`:

1. Fix every reported issue. Severity doesn't matter: SonarCloud's Reliability gate fails on any minor bug.
2. Before fixing, check whether SonarCloud already has the finding Accepted or Won't fix:

   ```bash
   T=$(grep -E '^SONAR_TOKEN=' .env | cut -d= -f2- | tr -d '"')
   curl -fsS -u "$T:" "https://sonarcloud.io/api/issues/search?componentKeys=ckoval7_fd-commander&rules=<rule>&ps=50" \
     | jq -r '.issues[]|[.component,.line,.issueStatus,.resolution]|@tsv'
   ```

   If it does, add the matching `sonar.issue.ignore.multicriteria` entry to `sonar-project.properties` so the local scan agrees. Don't change the code.
3. For a finding that really is intentional, document it in `sonar-project.properties` and ask the user before marking it Won't fix. Don't mark it yourself. SonarCloud closes these through `issues/add_comment` and then `issues/do_transition` with `transition=wontfix`.
4. Run Pint and the targeted tests for each fix, then `$S scan` again.

Sonar-only fixes that nobody would notice don't get a changelog entry.

The local file's ignore entries hide findings from the local scan that SonarCloud still reports, because SonarCloud doesn't read `sonar-project.properties`. Some are broad (all of `app/Livewire/**`, `app/Http/**`), so expect step 5 to turn up findings in those paths.

The scan covers all of `dev`, so findings can be old. Hand-check what `dev` changed since `main` (`git diff --stat origin/main..dev`) for rules the local analyzers miss:

- `.github/workflows/*.yml`: permissions set per job, not at the top level (`githubactions:S8264`/`S8233`)
- `.sh` files: `$1` and other positional parameters used directly in a function instead of assigned to a `local` first (`shelldre:S7679`, accepted for the one-line log helpers in `deploy.sh` and `update.sh`), unused `local` variables, repeated string literals, `curl -L` without `--proto '=https' --tlsv1.2`, `install -m 755`
- PHP closures and methods: unused parameters (`php:S1172`)
- Views: `@click`, `x-on:click` or `wire:click` on `<div>`, `<tr>`, `<li>`, `<span>`, `<svg>` or `<img>`. Use a native `<button>`.
- `role="dialog"` (`Web:S6819`): use a native `<dialog class="modal">` with `showModal()`.
- `alt` text that repeats "photo", "image" or "picture" (`Web:S6851`)

Known false positive: the HTML analyzer parses `<html>`, `<body>` and similar tag names inside a CSS or HTML comment as real markup, then reports `Web:PageWithoutTitleCheck` and `Web:S5254` on a page that already has a `<title>` and `lang`. Reword the comment so it doesn't contain the tag.

## 2. Trivy (iterate until clean)

```bash
docker pull -q aquasec/trivy:latest
docker run --rm -v "$PWD":/project:ro -v fielddaycommander_sail-trivy-cache:/root/.cache/trivy \
  aquasec/trivy:latest fs --scanners vuln --quiet \
  --skip-files /project/vendor/mockery/mockery/docs/requirements.txt \
  --skip-dirs /project/storage --skip-dirs /project/node_modules --skip-dirs /project/vendor \
  /project
```

This scans `composer.lock` and `package-lock.json`. The Mockery docs file is excluded on purpose (see the comment in `docker-compose.sail.yml`).

To fix a vulnerability, upgrade the affected package on a `fix/deps-<pkg>` branch: `composer update vendor/pkg --with-dependencies`, or for npm follow the lock-file rules in step 3. Re-scan until both targets show `0`. Commit as `fix(deps): upgrade <pkg> to <ver> (<CVE or GHSA id>)`, and run `$S scan` again if the upgrade changed code.

## 3. Update packages

On a `chore/deps-update` branch off `dev`. Run Composer in a one-off container (host PHP is too old):

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

- **Major version bumps** change the dependencies, so the user must approve them. List them with what would change, ask, and only bump the ones they approve.
- **Testing:** run the targeted tests for the areas the updated packages touch. Livewire and maryUI updates need tests that exercise components. Pest and PHPUnit updates need one test directory. Ask before running anything broader.
- **Re-scan:** run Trivy again, and `$S scan` too if updates changed any code, such as published vendor assets or config.
- **Commit:** `chore(deps): update composer and npm packages`. Merge into `dev` and demo.

## 4. Push and open the PR

```bash
git push origin dev
git -C ../fielddaycommander-demo push origin feature/demo-mode
gh pr list --base main --head dev
```

If a push is rejected because the remote moved, stop and tell the user. Don't pull, rebase or force-push without asking.

The PR is titled `Release vYY.MM.N`, with the version from step 0. If one is already open, the push has updated it; fix its title if it names a different version (`gh pr edit <n> --title "Release vYY.MM.N"`). Otherwise:

```bash
gh pr create --base main --head dev --title "Release vYY.MM.N" --body-file <scratchpad>/pr.md
```

Build the body from **Unreleased** in `CHANGELOG.md`: a short highlights list, then a Checks section with the local scan and Trivy results. End it with the PR attribution lines from the system reminder.

## 5. SonarCloud on the PR

```bash
$S pr <number>     # waits for the SonarCloud check on the PR head, then prints gate, issues, hotspots
```

Run it in the background, and don't pipe it through `tail` or `head`, which hides its exit code. It exits 0 only when the gate is `OK` with 0 open issues and 0 hotspots. Fix what it reports as in step 1 (on a branch, into `dev` and demo), push again, and re-run until it passes.

When SonarCloud flags something the local scan missed for a reason other than the local ignore entries, add the rule to the hand checks in step 1, and to `sonarcloud_gap_checks` in the script if it's greppable.

## 6. Version bump

Once both PR checks pass (`gh pr checks <number>`):

1. Re-check the version from step 0. If the month has rolled over or a tag has taken that number, work it out again and retitle the PR.
2. `git switch -c release/vYY.MM.N` off `dev`.
3. Check **Unreleased** in `CHANGELOG.md` against `git log --no-merges <last tag>..dev`. Every change a user or self-hoster would notice needs an entry, including this release's own fixes and package updates, following the Changelog section of `CONTRIBUTING.md`. A fix for a bug that never reached a release gets no entry. Write new entries through the `docs-prose` skill. If the section is empty, ask whether there's anything to release.
4. Change exactly three files:
   - `VERSION`: the new version, without the `v`
   - `charts/fd-commander/Chart.yaml`: `appVersion: "YY.MM.N"`. Leave the chart's own `version: 0.1.0` alone.
   - `CHANGELOG.md`: rename `## Unreleased` to `## YY.MM.N - YYYY-MM-DD` and add a fresh, empty `## Unreleased` above it.
5. Commit as `chore(release): vYY.MM.N`, merge into `dev` with `--no-ff`, delete the branch, and bring `dev` into demo.
6. Push both branches, then run `$S pr <number>` again until it passes on the bump commit.

A fix that lands after the bump but before the release goes out belongs to this version: put its changelog entry in the `YY.MM.N` section, not Unreleased.

For "promote dev", stop here and report: the PR link, the SonarCloud gate, the Trivy result, the version, and what was fixed. Run `$S down` first.

## 7. Ship

Never merge the PR with GitHub's buttons. Squash, merge commit and rebase all leave `main` with commits `dev` doesn't have, which breaks the next fast-forward. `main` only ever fast-forwards to `dev`; GitHub marks the PR merged once `main` contains its head.

1. Fast-forward `main` to `dev`: `git switch main && git merge --ff-only dev`, then `git switch dev`. If it doesn't fast-forward, go back to step 0's check.
2. Push `main` when the user asks for it. Otherwise tell them `main` is ready to push and stop.
3. Wait for SonarCloud's analysis of the new `origin/main` HEAD. `$S cloud` prints the revision of the last analysis; it must match `origin/main` and show gate `OK` with 0 open issues. If it doesn't, fix on a branch off `dev` and go back to step 4.
4. Create the annotated tag on `main`'s HEAD: `git tag -a vYY.MM.N -m vYY.MM.N`. Push it (`git push origin vYY.MM.N`) only when the user asks. The tag builds and publishes the Docker image.

## 8. Demo and clean up

Bring `dev` into `feature/demo-mode` and rebuild, following the `branch-workflow` skill, then remove the scan server:

```bash
$S down
```
