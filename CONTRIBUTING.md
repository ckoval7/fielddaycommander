# Contributing to FD Commander

Thanks for helping out. This page covers how branches flow, what a pull request needs, and how releases get cut.

## Branches

| Branch | What it holds | What can merge into it |
|--------|---------------|------------------------|
| `main` | The latest release. Every release tag points at a commit on `main`. | Only `dev` |
| `dev` | Finished work waiting for the next release. | Only feature branches |
| `feature/demo-mode` | The public demo site: `dev` plus the demo-only code. | `dev`, plus demo-only commits |
| Feature branches | One fix or feature each, cut from `dev`. | Nothing |

Both `main` and `dev` are protected. They can't be force-pushed or deleted, and changes reach them through pull requests. A check called `source-branch` fails any PR that breaks the table above, such as a feature branch aimed at `main`.

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

4. Open a pull request against `dev`. Say what changed and why, and include screenshots for anything you can see in the UI, from a phone as well as a desktop.

A PR with more than one commit is squash-merged into `dev`. A single-commit PR is merged as is.

## Releases

Releases are cut from `dev` by the maintainer. A release branch off `dev` gets the quality and security scans, package updates and the version bump, then merges back into `dev`. `main` is then fast-forwarded to `dev` and tagged `vYY.MM.N`. The tag builds and publishes the Docker image.

Because `main` only ever moves forward to match `dev`, it never has commits of its own, and fixes always go through `dev` first.

## Security issues

Don't open a public issue or PR for a vulnerability. Follow [SECURITY.md](SECURITY.md) instead.
