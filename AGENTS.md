# Website worktree context

Recorded 2026-10-21 after reviewing the website, repository README, tooling,
licensing, and recent website history.

## Scope and source of truth

- The user explicitly identified this branch/worktree as **website only**. The
  published website lives in `docs/` and is hosted on GitHub Pages.
- The application code elsewhere in this checkout is badly out of date. Do not
  use it to establish current app behavior or change it as part of website work.
- Branch at onboarding: `github.io`. Custom domain in `docs/CNAME`:
  `fielddaycommander.org`. No deployment workflow was found in this checkout;
  GitHub Pages repository settings were not inspected.
- Use the website as the working description of the product. Its claims have
  not been independently checked against the current app or contest rules.
  Consult current app sources or the user when a change depends on those facts.
- Preserve this scope when reading the Laravel guidance in `CLAUDE.md`: the
  website does not use the Laravel application build or runtime.

## Product understanding

Field Day Commander is an open-source operations platform for amateur radio
clubs running ARRL Field Day and Winter Field Day. The central message is the
whole event, beyond contact logging: deploy, plan, operate, and submit.

- Planning: events, station configuration and readiness, personal/club equipment
  inventories and commitments, volunteer shifts, invitations, safety checklists.
- Operation: shared multi-station QSO log, duplicate/exchange validation,
  station-mode browsers, GOTA operator tracking, section map, live dashboards
  and a large-screen view of event progress.
- Integrations: N1MM Logger+, WSJT-X/JTDX, and generic ADIF/fldigi over UDP;
  guided ADIF file import. Published default ports: 12060, 2237, 2238.
- Scoring/reporting: Field Day bonuses and power multipliers; Winter Field Day
  objectives and event-specific rules; Cabrillo, ADIF, CSV, submission PDFs.
- Supporting workflows: guestbook, NTS/ICS-213 messages, bulletin schedules and
  copies, photos, audit logs, paper log transcription, backup/restore.
- Four default roles: System Admin, Event Manager, Station Captain, Operator;
  permissions and custom roles are configurable.
- Designed for modest Linux hardware, including Raspberry Pi, and browsers on
  the local network. Core operation needs no internet; browser logging queues
  contacts during temporary server disconnections. Weather data and optional
  email require their respective connectivity/services. Distinguish local
  weather warnings from internet-fed NWS alerts and forecasts.
- App architecture described in the site: Laravel/Livewire, FrankenPHP/Caddy
  with Octane, Reverb WebSockets; scripted Linux install or Docker deployment.
  This describes the product, not a website runtime requirement.
- Primary calls to action: live demo at `https://demo.fielddaycommander.org/`,
  installation docs, and `https://github.com/ckoval7/fielddaycommander`.

## Website map and implementation

- Plain static HTML/CSS/JavaScript with no site build step or framework.
- `docs/index.html`: homepage with substantial inline styles, structured data,
  rotating hero text, screenshot links, and deployment command clipboard UI.
  Current narrative: product introduction with offline/logger/free reassurances,
  preparation (gear, shifts, safety), operator logging demonstration, NTS traffic
  and bonuses, big-screen progress, club leadership/submission, FAQ, installation.
  The hero starts consistently with the command-center phrase, then rotates
  through eleven user-selected headlines. Preserve the approved phrase mix.
  User prefers clear feature headings over vague slogans, removed the decorative
  signal divider and small phase timing labels, and wants offline capability clear.
  Shared footer uses one linked notice per license and a 24px logo in flex layout.
- `docs/fd-commander-features.html`: feature overview with screenshots and
  illustrative UI. `docs/fd-commander-docs.html`: extensive reference manual,
  its own sidebar navigation and inline interaction code.
- `docs/fd-commander-faq.html`: categorized FAQ.
- `docs/how-to/index.html` and 15 guides: Pi/SSH/LAN setup, first event,
  bonuses/objectives, safety, multi-station logging, station mode, GOTA,
  external loggers, fldigi, entry submission, backups, email, DNS/TLS.
- `docs/fd-commander.css`: shared styles, dark palette, blue/green accents,
  responsive navigation and page layouts. Pages also carry inline styles;
  inspect both before changing a layout. Fonts: Inter and JetBrains Mono,
  loaded from Google Fonts with local fallbacks.
- `docs/nav.html` / `nav.js` and `docs/footer.html` / `footer.js`: shared
  fragments fetched into each page. `{{root}}` resolves to an empty string at
  the root or `../` under `/how-to/`. New directory depths need care.
- `docs/toc.js`: generated heading IDs and responsive table of contents for
  `.guide-layout` and `.faq-section`, scroll tracking and deep-link handling.
- `docs/lightbox.js`: native dialog viewer for linked images directly inside
  `.screenshot`; supports modified clicks, captions, and normal image fallback.
- `docs/screenshots/`: existing app captures, generally light/dark pairs, plus
  `tv_dashboard.jpg`. Reuse relevant assets and meaningful alt text.
- SEO assets: per-page canonical/Open Graph/Twitter metadata, homepage JSON-LD,
  `sitemap.xml`, `robots.txt`, `og-image.png`, favicon, domain verification file.
  Keep these and shared navigation consistent when adding or moving pages.
- Licensing: `REUSE.toml` and `LICENSES/` describe the split. Website content
  and images are CC BY-NC-SA 4.0; CSS/JavaScript carry GPL-3.0-or-later headers.

## Local work and verification

- Preview the website over HTTP, e.g.
  `python3 -m http.server 8000 --directory docs` from the repository root.
  Opening HTML via `file://` will not properly exercise fetched fragments.
- Root npm/Vite scripts build the old application, not the website. Existing
  Playwright configuration targets application tests, not a dedicated site suite.
- Match verification to website changes: check local links/assets and HTML/JS
  as appropriate; inspect affected pages at desktop and mobile sizes for visual
  changes, including navigation, fragments, screenshot viewer and deep links.
- Do not install or start the outdated app merely to preview the website.
- Homepage revisions have been checked for balanced markup, unique IDs, local
  links/assets/anchors, JSON-LD parsing, and inline JavaScript syntax. Browser
  preview is not available in this environment; visual behavior still needs review.
  No production deployment or current app validation was performed.
- Marketing evaluation called for authentic club-use evidence when available.
  No testimonials or adoption statistics have been supplied; do not invent them.
