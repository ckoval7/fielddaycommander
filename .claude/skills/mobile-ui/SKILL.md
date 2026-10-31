---
name: mobile-ui
description: Use this skill when building, changing, reviewing or screenshotting any Field Day Commander screen that people use on a phone or tablet, which is every screen. Triggers include Blade or Livewire views, layouts, Mary UI or daisyUI components, forms and inputs, tables and lists, modals and drawers, sticky or fixed bars, responsive Tailwind classes, the logging and station-mode screens, browser or device testing, and any report that something looks wrong, cramped or wastes space on mobile. It sets the standing watch for wasted side space, touch target, input, layout, safe-area and viewport rules, the Docker and Playwright browser audit, and the checks to run before calling a UI change done.
---

# Mobile UI

Field Day Commander runs in a field. Phones and tablets are first-class devices, not an afterthought, and the conditions are harder than an office:

- Bright sun, so contrast matters more than usual.
- One-handed use, cold or gloved fingers, a radio in the other hand.
- A flaky LAN or no internet at all, often on a cheap Android tablet.
- Shared station computers and BYOD phones side by side. Never assume one device type.

Design for a 360px-wide phone first, then widen. If a screen works there in portrait and landscape, it almost always works everywhere.

## Standing watch: wasted side space

The user has asked for this to be checked every time, not only when it comes up. Whenever you build, edit, review or look at a screenshot of any screen, check the left and right margins at phone width, and fix or report stacked padding even if the task was about something else.

- **What it looks like:** fields, cards and text sit visibly in from the screen edge with a band of empty background on both sides. The profile page before commit `06dc772` was a typical case: a `container px-4` wrapper plus card padding pushed fields 40px in on a 390px phone. Reports was worse at 58px.
- **The budget:** the layout gives a 12px gutter on phones, and cards add 12px (an `app.css` rule already trims `.card.p-4`–`.card.p-8`, `.card-body` and maryUI's `.tab-content.px-3` below 40rem). Content edges belong about **24px** from the screen edge. Past **28px** means an extra layer is stacking.
- **The usual causes:** a page root wrapper with `px-4`, `p-4`, `p-6` or `container px-4`; a hand-built panel (`bg-base-100 shadow p-6`) that the card rule doesn't reach; a padded box nested inside a card; section bands with `px-6`.
- **The fixes:** `max-sm:px-0` on page wrappers (as `settings-page.blade.php` does), `p-3 sm:p-6` on custom panels, `max-sm:p-3` on nested boxes, `px-3 sm:px-6` on section bands. Prefer real `card`/`x-card` markup so the global rule applies.
- **Measure, don't guess:** run `scripts/gutter.py` (see "Browser audit" below), or read `getBoundingClientRect().left` on the page's inputs and paragraphs at 390px. A screenshot alone is enough to spot it; the measurement tells you which ancestor added the padding.

## How the app is built

Check these before adding anything new. Reuse beats reinvention.

| Concern | Where it lives |
|---|---|
| Components | Mary UI (`x-main`, `x-menu-item`, `x-table`, `x-input`, `x-modal`, `x-icon`) on daisyUI 5 and Tailwind 4. Custom components in `resources/views/components/`. No Flux. |
| Main layout | `resources/views/components/layouts/app.blade.php`. Viewport meta already has `viewport-fit=cover`. |
| Mobile chrome | Below `lg` there is a sticky collapsing header (`lg:hidden`) that publishes `--mobile-header-height`, and the sidebar becomes the `main-drawer` off-canvas drawer. At `lg` and up the desktop header and collapsible sidebar take over. There is no bottom nav. |
| Breakpoints | Tailwind defaults only. Mobile-first. `lg` is the mobile-to-desktop chrome switch. |
| Card/table pattern | Card list hidden at the breakpoint where the table starts to fit, beside the table. Narrow tables switch at `md` (`guestbook-manager`, `audit-log-viewer`); wide ones (events, users, equipment) switch at `xl` because the open sidebar eats width on tablets and small laptops. The logbook switches at `lg`. |
| Global touch CSS | `resources/css/app.css` has a `@media (pointer: coarse)` block: default, `sm` and `xs` buttons, inputs and selects get a 44px height, tabs and menu rows 44px, small checkboxes and radios 24px, and every field gets 16px text via daisyUI's `--font-size-min`. A phone-only block trims card padding. Rely on these before adding per-view classes, and extend them when a whole component class is wrong. |
| PWA | `components/pwa-meta.blade.php` (manifest link, icons, `theme-color`, Apple tags) is included in both layouts; the theme script keeps `theme-color` in step with the chosen theme. `public/manifest.webmanifest` plus icons in `public/icons/`, built from the 1024px original artwork. No service worker. |
| Theme | daisyUI `light`/`dark`, `dark:` keyed on `[data-theme="dark"]`. Contrast is enforced by `tests/Unit/ThemeContrastTest.php`. |
| Offline | `resources/js/components/contact-queue.js` queues contacts in localStorage and syncs via `ContactSyncController`. Connection UI in `components/dashboard/connection-monitor.blade.php`. |

## Rules

### Touch targets

- Every tappable control is at least **44×44 CSS px** (`min-h-11 min-w-11`). The global touch CSS covers daisyUI buttons, fields, tabs and menu rows; custom clickable elements (a `<button>` styled with utilities, an icon-only link) need `pointer-coarse:min-h-11` and `min-w-11` themselves. WCAG 2.2 AA (2.5.8) only requires 24px, but 44px matches Apple's HIG and is close to Material's 48dp. Primary actions on logging screens should be bigger than the minimum.
- Grow the hit area with padding, not a bigger icon. An icon-only button is `btn btn-square` or `p-2.5` around a 24px icon, never a bare `<x-icon>` with a click handler.
- Keep at least 8px (`gap-2`) between adjacent targets. Destructive actions never sit right next to the action people tap most.
- Inline text links are exempt, but a row of small links (pagination, filter chips) is not.
- Add `touch-manipulation` to buttons people tap rapidly (band, mode, power selectors, steppers) so double-tap zoom never swallows a tap.

### Thumb reach and layout

- Put the main action for a screen where a thumb reaches it: bottom half on phones, or full-width at the end of the form. Don't strand it in the top-right corner of the header.
- One column on phones. Use `grid-cols-1 sm:grid-cols-2` and similar, not a fixed two-column grid that squeezes inputs.
- No horizontal page scroll at 360px, ever. Long callsigns, frequencies, URLs and API tokens need `break-all` or `truncate` with the full value reachable.
- Rows of buttons or badges must wrap. Mary's `<x-header>` actions slot is `flex items-center gap-3` with no wrap, so two or three buttons push the page wider than a phone; give it `<x-slot:actions class="flex-wrap max-sm:w-full">`. Same for a hand-built title row with a badge beside it (`flex flex-wrap gap-x-3 gap-y-1`). Once the page is wider than the screen, modals and drawers size to the wider page and run off the edge too.
- Full-bleed blocks cancel the phone gutter with `max-sm:-mx-3` (12px). `-mx-5` is from the old 20px gutter and overflows by 8px.
- Don't waste the sides of the screen. See "Standing watch: wasted side space" above for the 24px budget, causes and fixes.
- Don't hide functionality on mobile. Move it (overflow menu, separate row, drawer) instead. The logging screen does this for band, mode and power with a separate `sm:hidden` row.

### Hover and pointer

- Tailwind 4's `hover:` only applies on devices that can hover. Anything revealed only on hover, such as row actions or edit icons, is invisible on touch. Show it always, or show it always on `pointer-coarse:`.
- Tooltips and `title` attributes don't exist on touch. Never put essential information or the only label for an icon there. Icon-only buttons need an `aria-label` and, if the meaning is not obvious, visible text on mobile.
- Use `pointer-coarse:` for touch-specific sizing and `any-pointer-coarse:` for hybrid devices (a laptop with a touchscreen). Don't sniff user agents.
- Every interaction needs a visible `active:` or pressed state. On touch that is the only feedback before the server answers.

### Forms and inputs

- **Input text is at least 16px on phones.** Smaller text makes iOS Safari zoom the page on focus. The global touch CSS already raises daisyUI `.input`, `.select` and `.textarea` to 16px, but a utility that sets `font-size` directly on the field (`text-xs`, `sm:text-sm`) beats it, so don't add one. A bare `<input>` without a daisyUI class gets no help. A fixed-width field (`w-28`) may clip its placeholder at 16px; widen it on touch (`pointer-coarse:w-36`).
- Never fix zoom with `maximum-scale=1` or `user-scalable=no`. That blocks pinch-zoom for people who need it.
- Pick the keyboard with `type`, `inputmode`, `autocomplete` and `enterkeyhint`:

| Field | Attributes |
|---|---|
| Callsign | `autocapitalize="characters" autocorrect="off" spellcheck="false" autocomplete="off"` |
| Exchange, grid square, section | Same as callsign. The exchange input also gets `enterkeyhint="send"`. |
| Frequency (MHz) | `inputmode="decimal"` |
| Power, RST, counts, serials | `inputmode="numeric" pattern="[0-9]*"` |
| Email | `type="email" autocomplete="email"` |
| Phone | `type="tel" autocomplete="tel"` |
| Password | `autocomplete="current-password"` or `new-password` |
| 2FA code | `inputmode="numeric" autocomplete="one-time-code"` |
| Name, address | the matching `autocomplete` token (`name`, `street-address`, `postal-code`) |

- These are hints. Validation still happens server side.
- Labels are always visible. Placeholders are examples, not labels.
- Errors show next to the field and the first invalid field gets focus. A message at the top of a long form is off-screen on a phone.
- Use native `<select>`, `type="date"` and `type="time"` on mobile when they fit. They give the platform picker, which beats any custom dropdown on a phone.
- Don't steal focus on page load on touch devices; it pops the keyboard over the screen. The logging screen is the exception, where focus after logging a contact is the whole point.

### Viewport height and the keyboard

- Don't use `h-screen` or `min-h-screen` for full-height layouts. `100vh` is the largest viewport on mobile and hides content behind the browser toolbar. Use `min-h-dvh` for layouts that should track the toolbar and `h-svh` when something must fit on first paint.
- None of the viewport units shrink for the on-screen keyboard. If something must stay above the keyboard, such as a send bar on the logging screen, position it with the `visualViewport` API in Alpine. `interactive-widget=resizes-content` works in Chrome 108+ and Firefox 132+ but not Safari, so it can't be the only fix.
- When an input gains focus near the bottom of the screen, make sure it scrolls into view and isn't covered by a sticky footer.

### Safe areas, sticky and fixed elements

- The viewport meta uses `viewport-fit=cover`, so on notched phones content runs under the notch and home indicator unless it is padded. Anything fixed or sticky to a screen edge pads with `env()`:
  - top: `pt-[env(safe-area-inset-top)]`
  - bottom: `pb-[max(0.75rem,env(safe-area-inset-bottom))]`
  - sides in landscape: `px-[max(0.75rem,env(safe-area-inset-left))]` and the right equivalent
- Offset sticky elements below the mobile header with `top-[var(--mobile-header-height)]`, the way `logging-interface.blade.php` does. Don't hard-code a pixel height.
- Give anchor targets `scroll-mt-[var(--mobile-header-height)]` so in-page links don't land under the header.
- Stack sticky bars sparingly. Header, banner and session bar together can eat a third of a landscape phone screen. Collapse or hide what isn't needed while scrolling.

### Tables and lists

Choose one pattern per table, by what people do with it:

1. **People act on rows** (edit, delete, open): use the card list pattern, cards with the important fields and actions plus the table where it fits. Follow `events-list.blade.php`. Pick the breakpoint by measuring the table at 810px and 1080px with the sidebar open, not by habit; a table that needs 900px switches at `xl`. Don't wrap a table that has row dropdowns in `overflow-x-auto`, because that clips the dropdowns.
2. **Read-only data with a few key columns**: hide the minor columns with `hidden md:table-cell` on both `th` and `td`, so the table fits at 360px without scrolling.
3. **Wide reference data** (logbook exports, audit logs): wrap in `overflow-x-auto`, make the first column `sticky left-0` with a background, and keep the scroll inside the table, never on the page.

Mary's `<x-table>` only gives you option 3 for free. If its mobile result is bad, switch to a card list rather than fighting it.

Long lists need pagination or `wire:loading`-aware infinite loading. Rendering hundreds of rows with Alpine per row is slow on a cheap tablet.

### Modals, drawers and menus

- On phones a modal is effectively full-screen or a bottom sheet. Constrain it with `max-h-[90dvh]` and scroll inside the body with `overflow-y-auto overscroll-contain` so the page behind doesn't scroll.
- The close and primary buttons stay reachable without scrolling. Put actions in a sticky modal footer.
- Every drawer and modal closes with a visible button, a backdrop tap and Escape.
- Dropdown menus must not open off-screen at 360px. Check the right-hand edge.

### Network, feedback and Livewire

- Assume every request is slow. Every action that hits the server shows `wire:loading` feedback and disables its button with `wire:loading.attr="disabled"` so a second tap doesn't submit twice.
- Prefer `wire:model.blur` or `wire:model` (deferred) over `wire:model.live` on mobile forms. Live binding sends a request per keystroke.
- Use `.debounce` on live search inputs (300ms or more).
- Never lose typed data on a failed request. The contact queue is the model to follow for logging data.
- Reverb live updates must keep working over LAN IPs and `.local` hosts. Don't break them for mobile fixes.

### Readability in sunlight

- Body text at least 16px on phones; secondary text no smaller than `text-sm`.
- Use theme tokens, not one-off colors, so `ThemeContrastTest` covers them. Add a token and a test case when you need a new color.
- Never convey state by color alone. Pair it with an icon or text (duplicate, offline, error).
- Respect `motion-reduce:` for animations, and keep animation short. It costs battery and frame rate on low-end devices.

## Testing

Every change needs a programmatic test (see CLAUDE.md). For mobile work that usually means a Pest feature test that renders the view and asserts the markup that makes it mobile-safe:

```php
it('gives the callsign field a mobile-friendly keyboard', function () {
    // ...arrange the user, event and operating session with factories...

    $this->actingAs($user)
        ->get(route('logging.session', $operatingSession))
        ->assertOk()
        ->assertSee('autocapitalize="characters"', false)
        ->assertSee('enterkeyhint="send"', false);
});
```

For Livewire components, use `Livewire::test(...)->assertSeeHtml(...)`.

### Browser layout tests (`tests/Browser`)

Feature tests only see the HTML; layout regressions need a real browser. `tests/Browser/LayoutTest.php` (Pest browser plugin, Playwright Chromium) opens every screen at phone 360, tablet 810, tablet 1024 (sidebar open) and desktop 1280, with a seeded event that fills each screen (`Support/FieldDayScene.php`: contacts, shifts with unresolved assignments, guestbook entries, equipment, messages, and a Winter Field Day variant). It also covers states that only appear after an action: logbook rows selected, a modal submitted empty so the validation summary opens, and station mode on an enrolled browser. `Support/layout-audit.js` fails a page for:

- sideways page scroll, or a control off screen outside a scroll container
- controls overlapping each other, or covered by another element at first paint
- content spilling out of its card, tile, stat, alert, modal or button
- touch targets under 44px tall or 24px wide (24px for checkboxes), and field text under 16px, on touch screens
- content sitting more than 28px from the left edge on phones (stacked padding)

Run it after any view, CSS or layout change, and before merging such a branch into `dev`. It doesn't run with `php artisan test`; it needs the `fdc-browser-tests` image (built automatically from `tests/Browser/Dockerfile`) and current assets (`npm run build`):

```bash
tests/Browser/run.sh                              # everything, about 7 minutes
tests/Browser/run.sh --filter='manage schedule'   # one screen, all widths
tests/Browser/run.sh --filter='rows_selected'     # one interaction test
```

When you add a screen, add its path to the `screens` dataset. When a state only appears after an action, add an interaction test like the logbook one. When a page has an intentional overlay that isn't a layout bug (like the sidebar scroll arrows), mark it `data-layout-audit-ignore` rather than loosening a check. Keep the scene deterministic: Faker is seeded, so a new random field shifts every name after it.

The Docker and Playwright audit below is still useful for screenshots, WebKit, and the side-by-side before/after comparison.

### Browser audit (Docker + Playwright)

Run this after any layout or styling change that touches more than one screen, and whenever the user asks for browser testing. Scripts live in `.claude/skills/mobile-ui/scripts/`:

- `setup.php` seeds an active event, station, operating session (id 1) and contacts, and makes `w1aw@example.com` / `password` a System Administrator so every page loads.
- `score.py` audits 28 screens on 7 profiles (phone 360, 390 and 844 landscape; tablet 810 and 1080; desktop 1280 and 1440) for sideways overflow, tap targets (44px on touch, 24px for checkboxes and on desktop), field text under 16px and script errors, and saves viewport screenshots. Env: `BASE`, `OUT` (results go to `score-$OUT.json`), `ROLE` (dev role switcher label such as `Operator`), `DEV` (one profile). Pass page paths as arguments to limit it.
- `gutter.py` measures the median side inset of fields and text at 360 and 390px, and for anything past 30px prints the padded ancestors that put it there.

Setup, from the main checkout (work dir `~/.cache/fdc-mobile-test`, since Docker can't see the scratchpad):

```bash
W=~/.cache/fdc-mobile-test; mkdir -p $W/db; cp .claude/skills/mobile-ui/scripts/* $W/; mv $W/setup.php $W/db/; touch $W/db/app.sqlite; chmod -R 777 $W
ENVS="-e APP_ENV=local -e DEMO_MODE=false -e DB_CONNECTION=sqlite -e DB_DATABASE=/data/app.sqlite -e SESSION_DRIVER=file -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e BROADCAST_CONNECTION=log -e APP_URL=http://fdc-app:8000 -e TRUSTED_HOSTS=fdc-app,localhost -e PHP_CLI_SERVER_WORKERS=4 -e XDEBUG_MODE=off"
docker network create fdc-mobile-test
docker run --rm -v "$PWD":/var/www/html -v $W/db:/data -w /var/www/html -u 1000:1000 $ENVS --entrypoint php sail-8.4/app artisan migrate:fresh --seed --seeder='Database\Seeders\DevSeeder' --force
docker run --rm -v "$PWD":/var/www/html -v $W/db:/data -w /var/www/html -u 1000:1000 $ENVS --entrypoint php sail-8.4/app /data/setup.php
npm run build
docker run -d --name fdc-app --network fdc-mobile-test -v "$PWD":/var/www/html -v $W/db:/data -w /var/www/html/public -u 1000:1000 $ENVS --entrypoint php sail-8.4/app -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
docker run -d --name fdc-pw --network fdc-mobile-test --ipc=host -v $W:/work -w /tmp mcr.microsoft.com/playwright/python:v1.49.0-noble sleep infinity
docker exec fdc-pw pip install -q --break-system-packages playwright==1.49.0
docker exec fdc-pw python3 /work/gutter.py            # side insets
docker exec -e OUT=after fdc-pw python3 /work/score.py # full audit
```

For a before/after comparison, check out `dev` in a second worktree, `npm run build` there, and run a second server (`fdc-before`) that mounts the main checkout's `vendor/` at `/var/www/html/vendor` (a symlinked `vendor` makes Composer load the branch's classes). Remove the containers, network and worktree when done.

Gotchas this recipe already works around, so don't undo them:

- `php artisan serve` strips most environment variables from its workers and reloads `.env`, which points at MySQL. Serve with `php -S` and Laravel's `server.php`, started from `public/`.
- Chromium drops touch emulation after the login redirect, so `pointer: coarse` turns false and results quietly test a mouse. `score.py` re-arms it over CDP after each navigation. Don't add `Emulation.setEmitTouchEventsForMouse`; it makes clicks hang. Check the `coarse` field in the results.
- The login click must not wait for navigation (`no_wait_after=True`, then `wait_for_url`), because the dashboard's first load can pass the click timeout.
- Ignore websocket, Boost browser-log and WebKit "access control checks" errors; Reverb isn't running in the audit.
- `sail-8.4/app` loads Xdebug in `develop` mode, which makes every request about 3× slower and the preview feel laggy. `XDEBUG_MODE=off` in `ENVS` turns it off. OPcache makes no measurable difference here.
- `score.py` only covers top-level pages. Also check detail pages for overflow: an album (`/gallery/{config}`), an event's equipment dashboard (`/events/{id}/equipment`), messages, and the transcribe screen (`/logging/transcribe/{station}`). Look up the ids in the seeded database; an event without a configuration 500s on its messages page, so pick one that has one.
- Look at the screenshots, not only the numbers. The audit missed a clipped placeholder and wasted side space that were obvious in a screenshot.

Then check the screen by hand:

1. Chrome DevTools device mode at 360×640, 390×844 (portrait and landscape) and 768×1024.
2. Both light and dark themes.
3. Throttle the network to "Slow 4G" and tap every action twice fast.
4. Tab through the form with a keyboard, since station computers use one.
5. Things DevTools can't emulate well need a real iPhone: focus zoom, safe areas, the keyboard covering inputs and toolbar resizing. Say so in the summary if you couldn't check them.

## Before you call it done

- [ ] No horizontal scroll at 360px
- [ ] No stacked side padding: fields and text sit about 24px (never past 28px) from the screen edge on phones. Check this on every screen you touched or looked at, even if the task was about something else.
- [ ] Every tap target 44px or larger, with space between neighbors
- [ ] Nothing important behind hover or a tooltip
- [ ] Inputs at least 16px, with the right `inputmode`, `autocomplete` and `enterkeyhint`
- [ ] No `h-screen`; edge-pinned elements use safe-area padding
- [ ] Tables use one of the three patterns
- [ ] Modals scroll inside themselves and keep their actions reachable
- [ ] Server actions show loading state and can't double-submit
- [ ] Works in light and dark; state not shown by color alone
- [ ] Feature test asserts the mobile-relevant markup
- [ ] `tests/Browser/run.sh` passes for the screens you touched
