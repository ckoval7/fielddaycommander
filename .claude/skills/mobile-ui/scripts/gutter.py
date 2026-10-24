"""Measure side insets of page content at phone widths. Fields and text past ~28px from the edge mean stacked padding."""
import json, os, statistics, sys
from playwright.sync_api import sync_playwright
BASE = os.environ.get("BASE", "http://fdc-app:8000")
OUT = os.environ.get("OUT", "gutter")
PAGES = sys.argv[1:] or ["/", "/logging/session/1", "/logging/transcribe", "/logbook", "/events", "/events/create",
    "/stations", "/stations/create", "/schedule", "/schedule/manage", "/schedule/my-shifts", "/equipment",
    "/equipment/create", "/guestbook", "/gallery", "/users", "/profile", "/settings", "/admin/audit-logs",
    "/admin/developer", "/admin/external-loggers", "/admin/import/adif", "/scoring", "/reports", "/weather",
    "/weather/manage", "/checklists", "/checklists/manage", "/section-map", "/w1aw-bulletin", "/logging"]
JS = r"""
() => {
  const root = document.querySelector('.drawer-content') || document.body;
  const vw = document.documentElement.clientWidth;
  const sel = 'input:not([type=checkbox]):not([type=radio]):not([type=hidden]), select, textarea, p, h1, h2, h3, h4, label, li, td, .card, table';
  const lefts = [], rights = [], deep = [];
  for (const el of root.querySelectorAll(sel)) {
    const r = el.getBoundingClientRect();
    if (r.width < 40 || r.height === 0) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    if (el.closest('.drawer-side, header, footer, [role=dialog], .modal, .dropdown-content')) continue;
    if (r.right > vw + 1 || r.left < -1) continue;
    lefts.push(Math.round(r.left)); rights.push(Math.round(vw - r.right));
    if (r.left > 30 && r.left < 120) {
      // record the padded ancestors that put a field far from the edge
      const chain = []; let p = el.parentElement;
      while (p && p !== document.body && chain.length < 12) {
        const ps = getComputedStyle(p); const pl = parseFloat(ps.paddingLeft) + parseFloat(ps.marginLeft) + parseFloat(ps.borderLeftWidth);
        if (pl >= 8) chain.push(`${p.tagName.toLowerCase()}.${(p.getAttribute('class')||'').split(/\s+/).slice(0,5).join('.')}:${Math.round(pl)}`);
        p = p.parentElement;
      }
      deep.push(`${Math.round(r.left)}px ${el.tagName.toLowerCase()} ← ${chain.join(' ← ')}`);
    }
  }
  return { lefts, rights, deep: [...new Set(deep)].slice(0, 6) };
}
"""
os.makedirs(f"/work/shots-{OUT}", exist_ok=True)
res = {}
with sync_playwright() as p:
    for name, eng, vp in [("phone-390", "webkit", {"width": 390, "height": 844}), ("phone-360", "chromium", {"width": 360, "height": 740})]:
        b = getattr(p, eng).launch(); ctx = b.new_context(viewport=vp, has_touch=True, is_mobile=eng == "chromium")
        pg = ctx.new_page(); pg.goto(f"{BASE}/login"); pg.fill('input[name=email]', 'w1aw@example.com'); pg.fill('input[name=password]', 'password')
        pg.click('button[type=submit]', no_wait_after=True); pg.wait_for_url(lambda u: '/login' not in u, timeout=90000, wait_until='commit'); pg.wait_for_load_state('load')
        for path in PAGES:
            try:
                pg.goto(f"{BASE}{path}", wait_until="networkidle", timeout=60000); pg.wait_for_timeout(300)
                a = pg.evaluate(JS)
                med = lambda xs: statistics.median(xs) if xs else None
                res.setdefault(path, {})[name] = {"left": med(a["lefts"]), "right": med(a["rights"]), "n": len(a["lefts"]), "deep": a["deep"]}
                pg.screenshot(path=f"/work/shots-{OUT}/{name}__{path.strip('/').replace('/', '_') or 'home'}.png")
            except Exception as e:
                res.setdefault(path, {})[name] = {"exception": str(e)[:200]}
        b.close()
json.dump(res, open(f"/work/{OUT}.json", "w"), indent=1)
print("done")
