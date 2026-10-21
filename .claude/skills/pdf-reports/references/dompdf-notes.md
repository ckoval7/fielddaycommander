# dompdf notes

Researched for dompdf **3.1.6** and barryvdh/laravel-dompdf **3.1.2** (the installed versions, October 2026). **[src]** means the claim was checked in `vendor/dompdf/dompdf`; a URL means a doc or issue confirms it; **[inferred]** means it's reasoned, not confirmed. After a dompdf upgrade, recheck anything marked [src] that you rely on.

## Contents

1. CSS support
2. @page and the root element
3. Page breaks
4. Headers, footers and page numbers
5. Fonts
6. Images and assets
7. Performance and memory
8. Security
9. Debugging and testing
10. laravel-dompdf API
11. Units

## 1. CSS support

- "Most of CSS 2.1 and some CSS 3": https://github.com/dompdf/dompdf/wiki/CSSCompatibility
- Since 3.0: `var()` custom properties, `calc()`, `min()`, `max()`, `clamp()`.
- `border-radius` (one radius per corner), 2D `transform`, `opacity`: supported. `box-shadow`: no (issue #1197).
- `display: flex | grid | flow-root` fall back to `block`; `inline-flex | inline-grid` to `inline-block`. No warning. [src `Css/Style.php` `_compute_display`] Issues #971, #2988 are open.
- `display: inline-block`: supported. [src]
- `box-sizing`: not implemented at all (no reference in `Style.php`). [src] Widths are content-box: `width: 100%` plus padding or border overflows its container.
- Floats are partial; `position: relative | absolute | fixed` work.
- `:hover` and other dynamic pseudo-classes never apply; `:nth-of-type` and friends are partial.
- `unicode-range` in `@font-face`: unsupported.

## 2. @page and the root element

- `@page` supports `margin` and `size`, and the selectors `:first`, `:left`, `:right`, `:odd`, `:even` (merged in `FrameReflower/Page::apply_page_style`). Named pages are skipped. On the selector variants only margins apply. [src `Css/Stylesheet.php` ~1403-1450]
- `border`, `padding` and `background-color` in `@page` don't work; style `body` instead. [src comment]
- Default page margin is 1.2cm (`lib/res/html.css`). [src]

- `Stylesheet::apply_styles` seeds the root `<html>` frame's style from the base `@page` style, then cascades the normal rules over it. The page reflower reads `margin_*` from that root style, so **`* { margin: 0 }` or `html { margin: 0 }` overrides the page margins**. [src `Stylesheet.php` ~1083-1235, `FrameReflower/Page.php` ~112-125] No doc or issue says this; it comes from the source and from Field Day Commander's own regression.

- Fixed-position offsets are also measured from the root style's margins (`Positioner/Fixed.php`). [src]

## 3. Page breaks

- Supported: `page-break-before`, `page-break-after`, `page-break-inside`, plus `orphans`/`widows` (default 2). `left`/`right` act like `always`. [src `Style.php` ~3400]
- The CSS3 names `break-before`, `break-after`, `break-inside` are **not** mapped in 3.1.6. [src]
- Break properties don't apply to table row groups; before/after on `tr` work since 1.1.0. (wiki)
- Forced breaks inside `fixed` or `absolute` elements are ignored. [src `FrameDecorator/Page.php` ~170]
- When a block splits across pages, the continuation gets `margin-top`, `padding-top` and `border-top` zeroed, and the first part loses its bottom ones (`body` exempt). On unforced breaks the child's top margin is dropped; on forced breaks it's kept. [src `AbstractFrameDecorator::split` ~710-752]
- Margin collapsing folds a block's top margin into the previous sibling's bottom margin and zeroes the block's own (`AbstractFrameReflower::_collapse_margins` ~166-188). [src] So an empty `page-break` div followed by a block with a negative top margin keeps that margin on the old page. Fix: put `page-break-before` on the block itself, or use `position: relative; top: -X` instead of a margin. [inferred from src, confirmed by rendering in this app]
- `<thead>` (`display: table-header-group`) repeats automatically: `Table::split` deep-copies header groups. `<tfoot>` is collected but **not** repeated. [src `FrameDecorator/Table.php` ~95-140]
- Table cells never split across pages; a very tall cell overflows the page and the footer. https://github.com/dompdf/dompdf/issues/3288
- `page-break-inside: avoid` on large tables has a history of problems (issues #554, #2190, #2395 "column widths ignored"). Use it on rows or small blocks, never on something taller than a page. [inferred from the issues]
- Without `<tbody>`, dompdf wraps each `tr` in its own anonymous row group and memory climbs sharply. https://github.com/dompdf/dompdf/issues/528
- `height: 100%` plus padding can produce a blank first page. (issue #793)

## 4. Headers, footers and page numbers

- At reflow, `FrameReflower/Page::reflow` removes every **direct child of `<body>`** with `position: fixed` and prepends a deep copy on each page. Nested fixed elements are not repeated. [src ~100-130]
- Fixed `top`/`left`/`bottom` are measured from the page margin edge (`y = top + margin_top`), so negative values sit inside the margin. [src `Positioner/Fixed.php` ~39-60]
- `counter(page)` works (`html { counter-reset: page }`, `body { counter-increment: page }` in `html.css`). There is no `pages` counter and no margin boxes. [src]
- "Page X of Y": after `render()`, `$canvas->page_text($x, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, $size, $rgb)`, or `$canvas->page_script(fn ($pageNumber, $pageCount, $canvas, $fontMetrics) => …)`. https://github.com/dompdf/dompdf/wiki/Usage. The x position is fixed before substitution, so measure with `$fontMetrics->getTextWidth()` against the widest number to right-align.
- Open bug #3767 (3.1.6): canvas text on page 1 takes the wrong colour when it equals the document's last fill colour (Cpdf colour cache). https://github.com/dompdf/dompdf/issues/3767

## 5. Fonts

- The 14 core PDF fonts are Windows-ANSI only. https://github.com/dompdf/dompdf/wiki/About-Fonts-and-Character-Encoding
- Generic `serif` maps to Times-Roman and `sans-serif` to Helvetica, both core fonts. [src `lib/fonts/installed-fonts.dist.json`] Use `DejaVu Sans`, `DejaVu Serif` or `DejaVu Sans Mono` for Unicode; all ship with regular, bold, oblique and bold-oblique.
- No emoji support and no glyph fallback chain (issue #1698).
- Font subsetting: dompdf defaults it on, laravel-dompdf and this app turn it off. Each embedded DejaVu face is about 700-760 KB.
- `@font-face` fonts are copied into `font_dir` and recorded in `installed-fonts.json`, so the directory must exist and be writable. Each weight and style needs its own rule.

## 6. Images and assets

- Remote fetching is off by default in laravel-dompdf 3.x and off in this app's config.
- `file://` paths must sit under `chroot` (here `public/`); `phar://` is off. laravel-dompdf sets the base path to `public_path()`, so relative `src` resolves there.
- `data:` URIs work, but in 3.1.0+ a custom `allowed_protocols` must list `data://`.
- SVG is rendered via php-svg-lib, which has had several security fixes (see 8). Pre-size bitmaps: big images cost memory, and huge declared dimensions were a DoS fixed in 3.1.6.

## 7. Performance and memory

- The whole frame tree, CSS and computed styles stay in memory until the Dompdf object is destroyed. A large table used about 28 MB, about 11 MB once objects were unset. https://github.com/dompdf/dompdf/issues/1489
- Long tables cause memory and timeout errors (issue #528, open).
- The `enable_html5_parser` option is deprecated; 3.x always uses masterminds/html5. [src `Options.php`]
- For small hardware [inferred]: write `<tbody>`, avoid nested tables, use `table-layout: fixed` with explicit widths (dompdf then uses its fixed cellmap, `Table.php`), use few fonts, avoid rendering twice, and for anything big render in a queued job.

## 8. Security

- Guidance: keep `isPhpEnabled` and `isRemoteEnabled` off (or restrict `allowedRemoteHosts`), set a tight `chroot`, never let users control options, and sanitize user HTML. https://github.com/dompdf/dompdf/wiki/Securing-dompdf
- Fixed in 3.1.6 (advisories published 20 July 2026, all moderate): chroot bypass by prefix match (CVE-2026-55554, GHSA-wvh6-f5jh-8gw4, `/var/www` matched `/var/www2`), file-existence oracle via `@font-face`, local file read via SVG in data URIs, SVG file-existence leaks, two BMP resource-exhaustion DoS. https://github.com/dompdf/dompdf/security/advisories
- Older: php-svg-lib < 0.5.2 phar RCE (GHSA-97m3-52wr-xvv2, 2024), CVE-2023-23924 SVG URI RCE, CVE-2022-28368 and CVE-2022-41343 font-install RCEs.
- User-entered text (bulletin copy, notes, names) goes through Blade `{{ }}` escaping. Never `{!! !!}` user data into a PDF template.

## 9. Debugging and testing

- Layout boxes: `setOption('debugLayout', true)`; sub-flags `debugLayoutLines`, `debugLayoutBlocks`, `debugLayoutInline`, `debugLayoutPaddingBox` (all default true once `debugLayout` is on). [src `Options.php`] Render with it once to see where a block's margins really are.
- Page count: `$pdf->render(); $pdf->getDomPDF()->getCanvas()->get_page_count()`.
- Root margins: `$pdf->getDomPDF()->getTree()->get_root()->get_style()`, then `length_in_pt($style->margin_top)`. The WFD and submission tests use this.
- HTML before PDF: `view('reports.x', $data)->render()` lets a test assert content without dompdf.
- Rasterizing and text: the `sail-8.4/app` image has Ghostscript (`gs -sDEVICE=png16m`, `-sDEVICE=txtwrite`). The host has none of gs, pdftoppm or pdftotext. `smalot/pdfparser` isn't installed. `scripts/render.sh` wraps the Ghostscript steps.

## 10. laravel-dompdf API

- Config keys are snake_case; `Options::set` camelizes them and maps `enable_php`, `enable_remote`, `enable_javascript`, `enable_font_subsetting` and so on to `is*Enabled`. **Unknown keys are ignored silently.** [src]
- `setOption()` changes one option on the existing set; `setOptions($array, $merge = false)` replaces them all unless `$merge` is true.
- `output()` renders if needed and returns the bytes. `stream()` sends `inline`; `download()` sends `attachment` with Content-Length. `save($path, $disk)` writes a file.
- `render()` then `getDomPDF()` gives the canvas for `page_text`. Calling `getDomPDF()->render()` directly bypasses laravel-dompdf's "rendered" flag, so a later `output()` renders a second time. Render through the wrapper, or output through `$dompdf->output()` as `exportPdf()` does.

## 11. Units

- `px` to pt is `px * 72 / dpi`; at the configured 96 DPI, 1px = 0.75pt. `cm` and `mm` convert at 72/2.54 and 72/25.4. [src `Style.php`]
- Letter is 612 × 792 pt (816 × 1056 px at 96 DPI). A4 is 595 × 842 pt. The config default paper is A4, but every controller calls `setPaper('letter', 'portrait')`; a new report must too.
