// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

// Layout audit run in the page by Tests\Browser\Support\LayoutAudit. Returns a
// list of problems per check; an empty list means the check passed. The checks
// mirror the mobile-ui skill's rules and its score.py/gutter.py audit.
async () => {
    // Let fonts, Livewire, Alpine and transitions settle before measuring.
    await document.fonts.ready;
    await new Promise((resolve) => setTimeout(resolve, 400));
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

    // Pages that focus a field on load (logging, transcribe) scroll it into view;
    // check the layout as it first paints, where nothing belongs under a sticky bar.
    window.scrollTo(0, 0);

    const vw = document.documentElement.clientWidth;
    const vh = document.documentElement.clientHeight;
    const coarse = matchMedia('(pointer: coarse)').matches;
    const phone = vw < 640;
    const drawerClosed = innerWidth < 1024 && !document.getElementById('main-drawer')?.checked;

    // The part of an element left showing after every clipping ancestor
    // (scroll areas, collapsed menus) cuts it down; null when nothing shows.
    const shownRect = (el) => {
        let { left, top, right, bottom } = el.getBoundingClientRect();
        for (let p = el.parentElement; p && p !== document.documentElement; p = p.parentElement) {
            const cs = getComputedStyle(p);
            if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') {
                const c = p.getBoundingClientRect();
                if (cs.overflowX !== 'visible') {
                    left = Math.max(left, c.left);
                    right = Math.min(right, c.right);
                }
                if (cs.overflowY !== 'visible') {
                    top = Math.max(top, c.top);
                    bottom = Math.min(bottom, c.bottom);
                }
            }
            if (cs.position === 'fixed') {
                break;
            }
        }
        return right - left > 0.5 && bottom - top > 0.5 ? new DOMRect(left, top, right - left, bottom - top) : null;
    };
    const visible = (el) => el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true, contentVisibilityAuto: true })
        && shownRect(el) !== null;
    const ignored = (el) => (drawerClosed && el.closest('.drawer-side'))
        || el.closest('details:has(#dev-role), .drawer-toggle, [data-layout-audit-ignore]')
        || el.closest('.sr-only');
    const describe = (el) => {
        const label = (el.getAttribute('aria-label') || el.textContent || el.getAttribute('title') || el.name || '')
            .trim().replace(/\s+/g, ' ').slice(0, 40);
        const id = el.id ? `#${el.id}` : '';
        const cls = (el.getAttribute('class') || '').split(/\s+/).filter(Boolean).slice(0, 4).join('.');
        return `<${el.tagName.toLowerCase()}${id}${cls ? '.' + cls : ''}> "${label}"`;
    };
    const size = (r) => `${Math.round(r.width)}x${Math.round(r.height)}`;
    // A scroll container that is itself on screen may hold wider content (wide tables).
    const insideScroller = (el) => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const ox = getComputedStyle(p).overflowX;
            if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') {
                return true;
            }
        }
        return false;
    };

    const controlSelector = 'button, a.btn, [role="button"], input:not([type="hidden"]), select, textarea, summary, label.btn, .menu a, .tab';
    // With a modal open, only the modal and anything above it in the top layer
    // (the validation summary popover) can be tapped; the page behind is inert.
    const modal = [...document.querySelectorAll('dialog[open], .modal.modal-open')].pop();
    const reachable = (el) => !modal || el.closest(':popover-open') || (modal.contains(el) && !el.closest('.modal-backdrop'));
    const controls = [...document.querySelectorAll(controlSelector)]
        .filter((el) => visible(el) && !ignored(el) && reachable(el));
    // The area a person actually taps: maryUI and daisyUI wrap fields, checkboxes
    // and selects in a sized label or box, and that wrapper takes the tap.
    const tapRect = (el) => {
        if ((el.type === 'checkbox' || el.type === 'radio') && el.closest('label')) {
            return el.closest('label').getBoundingClientRect();
        }
        const wrapper = el.parentElement?.closest('.input, .select, .textarea');
        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName) && wrapper) {
            return wrapper.getBoundingClientRect();
        }
        return el.getBoundingClientRect();
    };

    const problems = {
        overflow: [],
        offscreen: [],
        overlapping: [],
        covered: [],
        spilling: [],
        smallTargets: [],
        smallFonts: [],
        gutter: [],
    };

    // 1. The page itself never scrolls sideways.
    const overflow = document.documentElement.scrollWidth - vw;
    if (overflow > 1) {
        const culprits = [...document.querySelectorAll('body *')]
            .filter((el) => visible(el) && !ignored(el) && !insideScroller(el) && el.getBoundingClientRect().right > vw + 1)
            .filter((el) => ![...el.children].some((c) => c.getBoundingClientRect().right > vw + 1))
            .slice(0, 5)
            .map((el) => `${describe(el)} ends at ${Math.round(el.getBoundingClientRect().right)}px`);
        problems.overflow.push(`page is ${overflow}px wider than the ${vw}px viewport; ${culprits.join('; ')}`);
    }

    // 2. Controls sit on screen, unless they're in a scroll container.
    for (const el of controls) {
        const r = el.getBoundingClientRect();
        if ((r.right > vw + 1 || r.left < -1) && !insideScroller(el)) {
            problems.offscreen.push(`${describe(el)} spans ${Math.round(r.left)}-${Math.round(r.right)}px`);
        }
    }

    // 3. Controls never overlap each other.
    const rects = controls.map((el) => [el, shownRect(el)]);
    for (let i = 0; i < rects.length; i++) {
        const [a, ra] = rects[i];
        for (let j = i + 1; j < rects.length; j++) {
            const [b, rb] = rects[j];
            if (a.contains(b) || b.contains(a)) {
                continue;
            }
            const w = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
            const h = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
            if (w > 2 && h > 2) {
                problems.overlapping.push(`${describe(a)} and ${describe(b)} overlap by ${Math.round(w)}x${Math.round(h)}`);
            }
        }
    }

    // 4. Nothing (a sticky bar, a stray card) covers a control on screen.
    for (const [el, r] of rects) {
        const x = r.left + r.width / 2;
        const y = r.top + r.height / 2;
        if (x < 0 || y < 0 || x >= vw || y >= vh) {
            continue;
        }
        const hit = document.elementFromPoint(x, y);
        // A sticky table header legitimately covers rows scrolled beneath it.
        if (!hit || el.contains(hit) || hit.contains(el) || ignored(hit) || hit.closest('thead')) {
            continue;
        }
        // A label wrapping or naming the control passes taps through to it.
        const label = hit.closest('label');
        if (label && (label.contains(el) || (el.id && label.htmlFor === el.id))) {
            continue;
        }
        problems.covered.push(`${describe(el)} is under ${describe(hit)}`);
    }

    // 5. Nothing spills out of its box: a countdown running off its dashboard
    // tile, a label running out of its button, a table out of its card.
    const boxes = [...document.querySelectorAll('.card, .stat, .alert, .modal-box, .widget-item, .btn, .badge')]
        .filter((box) => visible(box) && !ignored(box));
    for (const box of boxes) {
        const b = box.getBoundingClientRect();
        for (const el of box.querySelectorAll('*')) {
            if (!visible(el) || ignored(el) || el.closest('.dropdown-content, [popover], .tooltip')) {
                continue;
            }
            // Content inside a scroll or clip container between it and the box is allowed to be wider.
            let clipped = false;
            for (let p = el.parentElement; p && p !== box; p = p.parentElement) {
                const cs = getComputedStyle(p);
                if (cs.overflowX !== 'visible' || cs.position === 'absolute' || cs.position === 'fixed') {
                    clipped = true;
                    break;
                }
            }
            const position = getComputedStyle(el).position;
            if (clipped || position === 'absolute' || position === 'fixed') {
                continue;
            }
            const r = el.getBoundingClientRect();
            if (r.right > b.right + 1 || r.left < b.left - 1) {
                problems.spilling.push(`${describe(el)} spans ${Math.round(r.left)}-${Math.round(r.right)}px, outside ${describe(box)} at ${Math.round(b.left)}-${Math.round(b.right)}px`);
                break;
            }
        }
    }

    // 6. Touch targets on touch screens: 44px tall, which the global touch CSS
    // gives buttons, fields, tabs and menu rows, and at least the WCAG 2.5.8
    // 24px wide (segmented buttons such as the section map's call areas run
    // narrower than 44px on purpose). Checkboxes and radios need 24px.
    if (coarse) {
        for (const el of controls) {
            if (el.tagName === 'TEXTAREA' || ['range', 'file', 'color'].includes(el.type)) {
                continue;
            }
            const r = tapRect(el);
            const selector = el.type === 'checkbox' || el.type === 'radio';
            const needHeight = selector ? 24 : 44;
            if (r.width < 23.5 || r.height < needHeight - 0.5) {
                problems.smallTargets.push(`${size(r)} ${describe(el)}`);
            }
        }
    }

    // 7. Field text is at least 16px on touch screens, or iOS zooms on focus.
    if (coarse) {
        for (const el of controls) {
            if (!['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)
                || ['checkbox', 'radio', 'range', 'file', 'submit', 'button', 'color'].includes(el.type)) {
                continue;
            }
            const fs = parseFloat(getComputedStyle(el).fontSize);
            if (fs < 16) {
                problems.smallFonts.push(`${fs}px ${describe(el)}`);
            }
        }
    }

    // 8. Content sits about 24px from the edge on phones; past 28px padding is stacking.
    if (phone) {
        const root = document.querySelector('.drawer-content') || document.body;
        const sel = '.alert, input:not([type=checkbox]):not([type=radio]):not([type=hidden]), select, textarea, p, h1, h2, h3, h4, label, li, td, .card, table';
        const lefts = [];
        for (const el of root.querySelectorAll(sel)) {
            const r = el.getBoundingClientRect();
            if (r.width < 40 || r.height === 0 || !visible(el) || ignored(el)) {
                continue;
            }
            // An alert's icon column sets its text in; the alert box itself is measured.
            if (el.closest('header, footer, [role=dialog], .modal, .dropdown-content') || el.parentElement?.closest('.alert')
                || r.right > vw + 1 || r.left < -1) {
                continue;
            }
            lefts.push([r.left, el]);
        }
        lefts.sort((a, b) => a[0] - b[0]);
        // Rows and tables set much of a page's content well in from the edge, so
        // measure the page's edge from its least-inset fifth of content.
        const edge = lefts.length ? lefts[Math.floor(lefts.length / 5)][0] : 0;
        if (edge > 28) {
            // Name the padded ancestors behind it.
            const [, deep] = lefts[Math.floor(lefts.length / 5)];
            const chain = [];
            for (let p = deep.parentElement; p && p !== document.body && chain.length < 6; p = p.parentElement) {
                const ps = getComputedStyle(p);
                const inset = parseFloat(ps.paddingLeft) + parseFloat(ps.marginLeft) + parseFloat(ps.borderLeftWidth);
                if (inset >= 8) {
                    chain.push(`${describe(p).split(' ')[0]}:${Math.round(inset)}px`);
                }
            }
            problems.gutter.push(`content sits ${Math.round(edge)}px from the left edge (budget 28px), e.g. ${describe(deep)} inside ${chain.join(' < ')}`);
        }
    }

    return { url: location.pathname, width: vw, coarse, problems };
}
