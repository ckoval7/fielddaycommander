# FD Commander

A self-hosted web app that ham radio clubs run during ARRL Field Day and Winter Field Day. It covers the whole event, not just logging: multi-operator contact logging with live dupe checks, real-time scoring with bonus tracking, station and equipment management, volunteer shifts, the guestbook, NTS message traffic and the net log, safety checklists, photos, and Cabrillo/ADIF export.

The Laravel, Livewire, Octane and testing conventions are in the `laravel-stack` skill. Activate it before writing any PHP, Blade or test code.

## Where it runs

These constraints shape most design decisions:

- **On the club's own LAN, often with no internet.** Nothing may need a CDN, external API or cloud service at runtime. Online-only features (weather) must fail quietly.
- **On small hardware.** A Raspberry Pi 4 with 2 GB of RAM on a battery pack is a supported target. Watch query counts, memory and background work.
- **From any device.** Operators log from phones, tablets and laptops, their own or the club's, at the same time. Never assume club-owned machines. Every screen has to work on a phone (activate the `mobile-ui` skill for view changes).
- **Live updates are core.** Scores, the logbook, the section map and dashboards update over Reverb websockets, including when the app is reached by LAN IP, a `.local` name or another hostname. Hardening must not break that (see `App\Support\LocalNetworkHosts` and `TrustedHosts`).
- **Flaky networks.** Contacts logged in the browser queue locally and sync when the connection returns.

## Domain map

- **Events and rule sets.** An `Event` has an `EventType` (`FD`, `WFD`) and a rules version. `App\Scoring\RuleSetFactory` maps each type and version to a frozen rule set in `app/Scoring/Rules`, with bonus strategies in `app/Scoring/Bonuses/<RuleSet>`. Never edit or remove an old rule set: past events keep scoring under the rules of their year. Add a new version instead.
- **Event context.** `EventContextService` (aliased as `ActiveEventService`) picks the active event by date, with a session override for browsing past events. Use it rather than querying for "the current event".
- **Logging.** `Contact`s belong to an `OperatingSession` at a `Station`. Dupe checks, band and mode resolution and exchange parsing live in `app/Services`. Rescoring runs through `RescoreService`.
- **External loggers.** N1MM Logger+, WSJT-X/JTDX, fldigi and generic ADIF-over-UDP send contacts to long-running listener commands (`app/Console/Commands/*ListenCommand.php`), which hand them to `ExternalContactHandler`. ADIF file import is a queued, multi-step wizard (`AdifImportService` and the `ImportAdifContacts`/`ValidateAdifImport` jobs).
- **Station mode.** An enrolled shared browser lets walk-up operators start a logging session by callsign with capped permissions (`App\Support\StationMode`, `EnforceStationMode`).
- **Roles.** System Admin, Event Manager, Station Captain and Operator, with editable permissions (spatie/laravel-permission). Gate features on permissions and policies, not role names.
- **Developer mode.** Clock override, role and callsign overrides for testing (`App\Support\DeveloperMode`). It's off in production unless explicitly allowed, and must stay that way.
- **Demo mode.** The public demo provisions a throwaway database per visitor. Its code lives only on `feature/demo-mode` (see Branches).

## Deployment paths

Self-hosters install with `deploy.sh` (bare Ubuntu/Debian), Docker (`Dockerfile`, `docker/`, `DOCKER.md`) or the Helm chart in `charts/fd-commander`, and upgrade with `update.sh` or a new image. A change to setup, config, env vars, services or the runtime must be made across all of them together, not just one. `backup.sh` and `restore.sh` must keep working.

## Branches, worktrees and tooling

- **Flow:** feature branch → `dev` → `main`. `main` and `dev` are protected: never commit directly to either.
- **Non-demo work:** in this checkout, on a feature branch off `dev`.
- **Demo work:** in the `../fielddaycommander-demo` worktree, directly on `feature/demo-mode`, which follows `dev`. Demo-only code never goes to `dev` or `main`.
- **Never push or offer to.** The user pushes.
- **PHP tooling runs in a container** (host PHP is too old), and Sail runs only from the demo worktree.

Activate the `branch-workflow` skill before you start, commit or merge work, touch the container, or run artisan, tests or Pint. It has the exact commands for each checkout.

## Writing

- **Commits:** Conventional Commits, describing the change in plain words (`fix(logging): show the first queued contact before it syncs`).
- **Changelog:** anything a user or self-hoster could notice gets a `CHANGELOG.md` entry under **Unreleased** in the same branch. Describe fixes by what the person saw and when, not what changed in the code. Skip refactors, typos and CI. `CONTRIBUTING.md` has the full rules.
- **User-facing prose** (UI copy, README, changelog, the website and docs in the `fielddaycommander-pages` worktree): activate the `docs-prose` skill.
- Only create documentation files when asked.

## Releases

Activate the `release` skill for any version bump, release or tag. A release runs a full Sonar scan until it is clean, a Trivy scan, and package updates before the version bump and tag.
