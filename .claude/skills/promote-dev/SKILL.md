---
name: promote-dev
description: Use this skill to get dev ready for main without cutting a release. Triggers include "scan dev and open a PR into main", "promote dev", "PR dev to main", or a request to run Sonar on dev, push dev and demo, and watch SonarCloud on the PR. It runs a local Sonar scan of dev, fixes findings, merges the fixes into demo, pushes dev and feature/demo-mode, opens or updates the dev → main PR, and fixes whatever SonarCloud reports on it.
---

# Promote dev

This gets `dev` to a state where its PR into `main` passes SonarCloud. It is not a release: no version bump, no tag, and `main` doesn't move. The `release` skill does that later.

Work in the **main checkout**. The `branch-workflow` skill covers the checkouts, merging rules and how to run PHP tooling.

**Pushing:** invoking this skill is the user's request to push `dev` and `feature/demo-mode`. Push only those two branches, only in steps 4 and 6. Never push `main` or tags, and never force-push.

```bash
S=.claude/skills/release/scripts/sonar-local.sh
```

## 1. Prepare

```bash
git status --short                          # must be clean, on dev
git switch dev && git pull --ff-only
git -C ../fielddaycommander-demo status --short
git -C ../fielddaycommander-demo log --oneline -1
```

If either checkout has uncommitted changes, stop and ask.

## 2. Local scan

```bash
$S up        # throwaway SonarQube with SonarCloud's profiles; reuses a running one
$S scan
```

Before fixing anything, check whether a finding is already handled in SonarCloud. Search by rule for Accepted or Won't fix issues:

```bash
T=$(grep -E '^SONAR_TOKEN=' .env | cut -d= -f2- | tr -d '"')
curl -fsS -u "$T:" "https://sonarcloud.io/api/issues/search?componentKeys=ckoval7_fd-commander&rules=<rule>&ps=50" \
  | jq -r '.issues[]|[.component,.line,.issueStatus,.resolution]|@tsv'
```

If it's Accepted there, add the `sonar-project.properties` entry so the local scan matches, as the `release` skill describes. Don't change the code.

The scan covers all of dev, so findings can be old. Look at what dev changed since `main` with `git diff --stat origin/main..dev`. Hand-check that diff for rules the local analyzers miss:

- `.github/workflows/*.yml`: permissions set per job, not at the top level (`githubactions:S8264`/`S8233`)
- `.sh` files: `$1` and other positional parameters used directly in a function instead of assigned to a `local` first (`shelldre:S7679`), unused `local` variables, repeated string literals, `curl -L` without `--proto '=https' --tlsv1.2`, `install -m 755`
- PHP closures: unused parameters (`php:S1172`)
- Views: `@click`, `x-on:click` or `wire:click` on `<div>`, `<tr>`, `<li>`, `<span>`, `<svg>` or `<img>`. Use a `<button>`.

Known false positive: the HTML analyzer parses `<html>`, `<body>` and similar tag names inside a CSS or HTML comment as real markup. It then reports `Web:PageWithoutTitleCheck` and `Web:S5254` on a page that already has a `<title>` and `lang`. Reword the comment so it doesn't contain the tag.

## 3. Fix findings

If there's nothing to fix, go to step 4.

1. `git switch -c fix/sonar-<topic>` off `dev`.
2. Fix the code. Run Pint and the targeted tests for anything you changed in PHP or Blade.
3. `$S scan` again until it shows 0 issues and 0 hotspots and the gap checks print `none`.
4. Commit with a Conventional Commit message. Sonar-only fixes that nobody would notice don't get a changelog entry.
5. Merge into `dev` (`--no-ff` for one commit, squash for more) and delete the branch.
6. Bring `dev` into demo, as the `branch-workflow` skill describes. Run `npm run build` only if assets changed, `view:clear` or `optimize:clear` for view or config changes, and migrations only if the merge brought new ones.

## 4. Push

```bash
git push origin dev
git -C ../fielddaycommander-demo push origin feature/demo-mode
```

If a push is rejected because the remote moved, stop and tell the user. Don't pull, rebase or force-push without asking.

## 5. Open or update the PR

```bash
gh pr list --base main --head dev
```

If a PR is already open, the push has updated it. Otherwise open one:

```bash
gh pr create --base main --head dev --title "Merge dev into main" --body-file <scratchpad>/pr.md
```

Build the body from **Unreleased** in `CHANGELOG.md`: a short highlights list, then a Checks section with the local scan result. End it with the PR attribution lines from the system reminder.

## 6. Watch SonarCloud on the PR

```bash
$S pr <number>     # waits for the SonarCloud check on the PR head, then prints gate, issues, hotspots
```

Run it in the background, and don't pipe it through `tail` or `head`, which hides its exit code. It exits 0 only when the gate is `OK` with 0 open issues and 0 hotspots. When something is reported, fix it on a branch off `dev` (step 3), push again (step 4), and run `$S pr` again. The push updates the PR. Repeat until it passes.

For a finding that is truly intentional, document it in `sonar-project.properties` and ask the user before marking it Won't fix. Don't mark it yourself.

## 7. Finish

```bash
$S down
```

Report the PR link, the SonarCloud gate, and what was fixed. Don't merge the PR. `main` only moves through the `release` skill.

When SonarCloud flagged something the local scan missed, add the rule to the hand checks in step 2. If it's greppable, also add it to `sonarcloud_gap_checks` in the script.
