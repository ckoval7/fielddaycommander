# Contributing to Field Day Commander

Thanks for helping out. This page covers how branches flow, what a pull request needs, and how releases get cut.

## Branches

| Branch | What it holds | What can merge into it |
|--------|---------------|------------------------|
| `main` | The latest release. Every release tag points at a commit on `main`. | Only `dev` |
| `dev` | Finished work waiting for the next release. | Only feature branches |
| `feature/demo-mode` | The public demo site: `dev` plus the demo-only code. | `dev`, plus demo-only commits |
| Feature branches | One fix or feature each, cut from `dev`. | Nothing |

Both `main` and `dev` are protected. They can't be force-pushed or deleted, and changes reach them through pull requests. `main` is the default branch, so the repo page shows the latest release. A check called `source-branch` keeps PRs on the right path: a feature-branch PR opened against `main` is moved to `dev` automatically, and a PR that breaks the table above in any other way fails. PRs also have to pass the SonarQube Cloud quality gate (the `SonarCloud Code Analysis` check), which runs on its own for every PR.

The demo branch follows `dev` rather than `main`, so the demo site gets fixes as soon as they land instead of waiting for a release. Demo-only code (the demo seeder, controller, middleware, commands and config) stays on `feature/demo-mode` and never merges into `dev` or `main`.

## Making a change

1. Fork the repo, or create a branch if you have write access. Start from an up-to-date `dev`:

   ```bash
   git switch dev && git pull
   git switch -c fix/short-description
   ```

   Name the branch after the change: `fix/...`, `feat/...`, `chore/...` or `docs/...`.

2. Keep the branch to one change. Write [Conventional Commits](https://www.conventionalcommits.org/) messages, such as `fix(logging): show the first queued contact before it syncs`.

3. Add or update tests for what you changed, then run the affected tests and the formatter:

   ```bash
   php artisan test --compact tests/Feature/YourTest.php
   vendor/bin/pint --dirty
   ```

4. Before you open the pull request, bring in the latest `dev` so your change is tested against what's there now:

   ```bash
   git fetch origin && git merge origin/dev     # or: git rebase origin/dev
   ```

   Open the pull request against `dev`. If you forget and open it against `main`, it gets moved to `dev` for you. The same goes for a branch you started from `main`: it merges into `dev` cleanly, since everything on `main` is already on `dev`, but merge in the latest `dev` before review. If `dev` moves on while your PR is open, update your branch again before it merges.

   In the PR, say what changed and why, and include screenshots for anything you can see in the UI, from a phone as well as a desktop.

A PR with more than one commit is squash-merged into `dev`. A single-commit PR is merged as is.

## Changelog

If your change is something a user or a self-hoster could notice, add a line under **Unreleased** in [CHANGELOG.md](CHANGELOG.md), in the same PR. Use the heading that fits: **Added**, **Changed**, **Fixed**, **Removed** or **Security**. If an upgrade needs someone to do something, such as set a new `.env` value or follow a breaking change, add it under **Upgrade notes**, first in the section.

The changelog is how someone finds out whether the problem they hit has been fixed, so for a fix, describe what the person saw and when it happened, not what changed in the code:

- Good: "On screens between 1024 and 1280 pixels wide with the sidebar open, the buttons on station cards spilled into the next card."
- Not useful: "Fixed station card layout." or "Use a container query for the station grid."

Keep the conditions that narrow it down (screen size, offline, a particular mode or band, a station-mode laptop, the first contact of an event), because those are what let someone recognize their own case. One or two sentences per entry is plenty. Several small fixes to the same screen can share one entry.

Leave out changes nobody would notice: typos, wording tweaks, refactors, tests, CI, formatting, and dependency bumps that don't fix anything or need action. When you're not sure, ask whether anyone would ever search the changelog for it.

Also leave out fixes for bugs that never reached a release. If the problem came in with a change still under **Unreleased**, nobody running a release ever saw it, so fold the fix into that work instead of adding an entry.


## Releases

Releases are cut from `dev` by the maintainer. `dev` gets the quality and security scans and package updates, then a `Release vYY.MM.N` pull request into `main` has to pass SonarCloud before the version bump lands on `dev`. `main` is then fast-forwarded to `dev` and tagged `vYY.MM.N`. The tag builds and publishes the Docker image.

Because `main` only ever moves forward to match `dev`, it never has commits of its own, and fixes always go through `dev` first.

## Security issues

Don't open a public issue or PR for a vulnerability. Follow [SECURITY.md](SECURITY.md) instead.
