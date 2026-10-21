"""Usability scoring audit for Field Day Commander, run against one server (BASE) and saved under OUT."""
import json
import os
import sys
from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://fdc-app:8000")
OUT = os.environ.get("OUT", "after")
ROLE = os.environ.get("ROLE")
ONLY = sys.argv[1:]

GUEST_PAGES = ["/login", "/register", "/public"]
AUTH_PAGES = [
    "/", "/logging/session/1", "/logging/transcribe", "/logbook", "/events", "/events/create",
    "/stations", "/stations/create", "/schedule", "/schedule/manage", "/schedule/my-shifts",
    "/equipment", "/equipment/create", "/guestbook", "/gallery", "/users", "/profile", "/settings",
    "/admin/audit-logs", "/scoring", "/reports", "/weather", "/site-safety", "/section-map",
    "/w1aw-bulletin",
]

# name, form factor, engine, viewport, touch
DEVICES = [
    ("phone-360", "phone", "chromium", {"width": 360, "height": 740}, True),
    ("phone-390", "phone", "webkit", {"width": 390, "height": 844}, True),
    ("phone-land", "phone", "webkit", {"width": 844, "height": 390}, True),
    ("tablet-810", "tablet", "webkit", {"width": 810, "height": 1080}, True),
    ("tablet-land", "tablet", "webkit", {"width": 1080, "height": 810}, True),
    ("desktop-1280", "desktop", "chromium", {"width": 1280, "height": 800}, False),
    ("desktop-1440", "desktop", "chromium", {"width": 1440, "height": 900}, False),
]
if os.environ.get("DEV"):
    DEVICES = [d for d in DEVICES if d[0] == os.environ["DEV"]]

NOISE = ("WebSocket", "browser-logs", "Failed to send logs", "[object Object]", "ResizeObserver", "access control checks")

AUDIT_JS = r"""
(minTarget) => {
  const vw = document.documentElement.clientWidth;
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return false;
    const cs = getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > 0;
  };
  const describe = (el) => {
    const label = (el.getAttribute('aria-label') || el.textContent || el.getAttribute('title') || el.name || '').trim().replace(/\s+/g, ' ').slice(0, 40);
    return `${el.tagName.toLowerCase()}.${(el.getAttribute('class') || '').split(/\s+/).slice(0, 4).join('.')} "${label}"`;
  };
  const drawerClosed = innerWidth < 1024 && !document.getElementById('main-drawer')?.checked;

  // Targets: buttons and controls; inline text links are exempt under WCAG 2.5.8.
  const sel = 'button, a.btn, [role="button"], input[type="checkbox"], input[type="radio"], select, summary, label.btn, .menu a, .tab';
  let targets = 0; const smallTargets = [];
  for (const el of document.querySelectorAll(sel)) {
    if (!visible(el)) continue;
    if (drawerClosed && el.closest('.drawer-side')) continue;
    if (el.closest('#dev-role-switcher, [data-dev-tools]')) continue;
    let r = el.getBoundingClientRect();
    const isSelector = el.type === 'checkbox' || el.type === 'radio';
    if (isSelector && el.closest('label')) r = el.closest('label').getBoundingClientRect();
    // maryUI wraps native selects in a sized label.select; that label is the tap area.
    if (el.tagName === 'SELECT' && el.closest('label.select')) r = el.closest('label.select').getBoundingClientRect();
    // Checkboxes, radios and toggles are held to the WCAG AA 24px minimum.
    const need = isSelector ? Math.min(minTarget, 24) : minTarget;
    targets++;
    if (r.width < need || r.height < need) smallTargets.push(`${Math.round(r.width)}x${Math.round(r.height)} ${describe(el)}`);
  }

  let fields = 0; const smallFonts = [];
  for (const el of document.querySelectorAll('input, select, textarea')) {
    if (['checkbox', 'radio', 'hidden', 'range', 'file', 'submit', 'button', 'color'].includes(el.type)) continue;
    if (!visible(el)) continue;
    fields++;
    const fs = parseFloat(getComputedStyle(el).fontSize);
    if (fs < 16) smallFonts.push(`${fs}px ${describe(el)}`);
  }

  return {
    coarse: matchMedia('(pointer: coarse)').matches,
    overflow: Math.max(0, document.documentElement.scrollWidth - vw),
    targets, smallTargets,
    fields, smallFonts,
    manifest: !!document.querySelector('link[rel="manifest"]'),
    themeColor: !!document.querySelector('meta[name="theme-color"]'),
  };
}
"""


def login(page):
    page.goto(f"{BASE}/login")
    page.fill('input[name="email"]', "w1aw@example.com")
    page.fill('input[name="password"]', "password")
    page.click('button[type="submit"]', no_wait_after=True)
    page.wait_for_url(lambda url: "/login" not in url, timeout=90000, wait_until="commit")
    page.wait_for_load_state("load")
    if ROLE:
        page.select_option(r'select[wire\:model\.live="role"]', label=ROLE)
        page.wait_for_load_state("networkidle")
        page.wait_for_timeout(800)


def main():
    os.makedirs(f"/work/shots-{OUT}", exist_ok=True)
    results = {}
    with sync_playwright() as p:
        for name, factor, engine, viewport, touch in DEVICES:
            browser = getattr(p, engine).launch()
            ctx = browser.new_context(viewport=viewport, is_mobile=touch and engine == "chromium",
                                      has_touch=touch, device_scale_factor=2)
            page = ctx.new_page()
            cdp = ctx.new_cdp_session(page) if engine == "chromium" and touch else None
            errors = []
            page.on("console", lambda m: errors.append(m.text) if m.type == "error" and not any(n in m.text for n in NOISE) else None)
            page.on("pageerror", lambda e: errors.append(str(e)) if not any(n in str(e) for n in NOISE) else None)
            for auth, paths in ((False, GUEST_PAGES), (True, AUTH_PAGES)):
                if auth:
                    login(page)
                for path in paths:
                    if ONLY and path not in ONLY:
                        continue
                    errors.clear()
                    entry = {"factor": factor}
                    try:
                        resp = page.goto(f"{BASE}{path}", wait_until="networkidle", timeout=60000)
                        if cdp:
                            cdp.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
                        page.wait_for_timeout(400)
                        entry.update(page.evaluate(AUDIT_JS, 44 if touch else 24))
                        entry["status"] = resp.status if resp else None
                        entry["errors"] = list(errors)
                        slug = path.strip("/").replace("/", "_") or "home"
                        page.screenshot(path=f"/work/shots-{OUT}/{name}__{slug}.png")
                    except Exception as exc:  # noqa: BLE001
                        entry["exception"] = str(exc)[:300]
                    results.setdefault(path, {})[name] = entry
            browser.close()
    tag = f"-{ROLE.lower()}" if ROLE else ""
    with open(f"/work/score-{OUT}{tag}.json", "w") as f:
        json.dump(results, f, indent=1)
    print("done", OUT)


main()
