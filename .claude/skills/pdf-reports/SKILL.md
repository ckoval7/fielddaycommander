---
name: pdf-reports

description: Use this skill for any work on a PDF that Field Day Commander produces, and before touching a PDF template, its controller or its tests. That covers the dompdf reports (ARRL submission sheet, Winter Field Day event summary, and the equipment delivery checklist, station inventory, owner contacts, return checklist and incident report), the FEMA ICS forms filled in the browser with pdf-lib, and print-styled pages like the ICS-309. Triggers include page margins, headers, footers, banners, page numbers or "Page X of Y", page breaks, long tables, rows under the footer, blank strips or blank pages, text off the page edge, fonts or missing characters, a report that "looks broken", adding a new PDF report, and any dompdf config or option change. It explains how dompdf actually lays out pages, the patterns that work here, and the render-and-look check to run before calling a PDF change done.

---

# PDF reports

PDFs have broken in this app more often than almost anything else, and nearly always the same way: a CSS change that looked right in the template did something different in dompdf, and nobody looked at every page of every report afterwards. dompdf is not a browser. It implements CSS 2.1 plus a few CSS3 bits, it has its own rules for page margins and breaks, and a fix for one report can quietly break another that shares the pattern.

Two habits prevent most of it:

1. **Know the page model** before editing (below). Most bugs here came from not knowing that `@page` margins live on the root element, or that a block after a forced break loses its top margin.
2. **Render and look at every page** of every report the change can reach, with enough data to fill several pages, before saying it's done. `scripts/render.sh` does this in one command. Tests passing proves nothing about layout.

`references/dompdf-notes.md` has the researched detail (CSS support, page breaks, fonts, security, performance, debugging) with sources. Read it when you hit something this file doesn't cover, or before adding a new report.

## The three ways the app makes PDFs

| Route | What | Where |
|---|---|---|
| dompdf on the server | ARRL submission sheet, WFD event summary | `ReportController`, `resources/views/reports/*-pdf.blade.php` |
| dompdf on the server | Equipment delivery checklist, station inventory, owner contacts, return checklist, incident report | `Equipment\EquipmentReportController::exportPdf()`, `resources/views/equipment/reports/` (shared `layout.blade.php`, `partials/paginated-table.blade.php`) |
| pdf-lib in the browser | FEMA ICS-205, 211, 213 fillable forms | `IcsFormController` + `IcsFormBuilder` (JSON field maps), `resources/js/components/ics-pdf.js`, blank forms in `public/forms/ics/`. Currently on `feature/non-contest-events`. |
| Browser print | ICS-309 net log | `resources/views/ics/309.blade.php`, a normal HTML page with `@media print` rules. Real CSS (grid works), printed by the browser, not dompdf. |

All of them must work with no internet: no remote fonts, images or scripts. dompdf has `enable_remote => false`, and pdf-lib is lazy-loaded from the app's own bundle.

PDFs are served inline (`stream()` or `Content-Disposition: inline`) and their links open in a new tab, so people print or save from the browser's viewer.

## The dompdf page model

Letter paper is 612 × 792 pt. At the configured 96 DPI, **1px = 0.75pt**, so CSS `48px` is 36pt. Keep that in mind when you do geometry in points (the table chunker does).

### Page margins live on the root element

dompdf copies the `@page` margins onto the root `<html>` element's style. A reset like `* { margin: 0 }` also matches `<html>` and silently wipes the page margins, so content runs to the paper edge and under fixed footers. Every template here therefore resets like this:

```css
* { padding: 0; box-sizing: border-box; }
/* dompdf seeds the root element's style from @page, so a margin reset
   on * (which matches the html element) would wipe out the page margins. */
body, body * { margin: 0; }
```

Never reintroduce a bare `* { margin: 0 }`. `WfdReportsTest` and the report controller tests check the root margins are non-zero after render; copy that assertion for any new template.

Current margins: equipment layout `@page { margin: 60px 0 72px; }`; submission sheet and WFD summary `@page { margin: 32px 0 88px; }`. Horizontal margins are 0 so the blue banner can run edge to edge; content gets its side margin from `.section { margin: 0 48px … }`.

### Full-bleed banners: pull up into the margin

The blue `.header-bar` should meet the top edge of the paper. Since the page has a real top margin, give the banner a negative top margin of the same size (`margin-top: -60px` in the equipment layout, `-32px` in the other two). Change both numbers together.

An empty `<div class="page-break">` followed by the banner loses the banner's negative margin: dompdf collapses the banner's top margin into the empty div before the break, so the margin stays behind on the previous page and the banner sits below a white strip. Put the break **on the banner itself**:

```blade
<div class="header-bar page-break">   {{-- not <div class="page-break"></div> then the banner --}}
```

dompdf parses `@page :first`, `:left` and `:right`, but only margins apply there and the root element still carries the base margins. Don't build on them; the negative-margin pattern is tested and works on every page.

### Footers: two patterns, pick by what you need

- **CSS fixed footer in the bottom margin** (submission sheet, WFD summary): `position: fixed; bottom: -60px;` with an `@page` bottom margin big enough to hold it (88px). A fixed element repeats on every page. A negative `bottom` places it inside the margin, so body content can never run under it. If you put a fixed footer at `bottom: 24px` instead, it sits inside the content area and long pages print under it, which was a real bug.
- **Canvas footer** (equipment reports): `App\Support\PdfPageFooter::render($pdf, $left, $right)` renders the PDF and draws the rule and both labels on every page with `$canvas->page_text()` / `page_line()`. Use it for any new report that needs a page total. This is the only way to get a reliable "Page X of Y": dompdf substitutes `{PAGE_NUM}` and `{PAGE_COUNT}` at output. CSS `counter(page)` works, but **`counter(pages)` does not exist in dompdf** (it renders 0). It measures right-aligned text against the widest substituted form, so keep that if you change it. Known bug in 3.1.6 ([#3767](https://github.com/dompdf/dompdf/issues/3767)): canvas text on page 1 can take the wrong colour when its colour equals the document's last fill colour; if page 1's footer colour looks off, that's why.

The submission sheet shows "Page N of M" with `counter(page)` plus a precomputed total. A long bulletin can add pages, so the controller renders once, reads `getCanvas()->get_page_count()`, and re-renders with the real total when it differs. Keep that if you touch it, and copy it for any CSS-footer report whose length varies. Short reports still render only once.

dompdf can swallow the space between literal text and a `counter()` pseudo-element ("Page1"). If that happens, put the word in the rule: `content: "Page " counter(page);`.

### Long tables: pre-chunk, don't trust dompdf to split them

dompdf 3.x splits long tables badly when combined with repeated `<thead>` and `page-break-inside` hints: rows render under the footer or straddle pages. The equipment reports use `partials/paginated-table.blade.php`, which slices rows into page-sized chunks, each its own table with its own header and `page-break-before: always`.

The chunker works from an estimated row height (`rowHeight` callable, in pt) against a budget (`firstChunkBudget`, `chunkBudget`). It can only be as good as the estimate:

- If rows wrap (long names, notes, bands, circumstances), estimate from the longest wrapping cell, not a fixed 20/30pt. Too-low estimates make a chunk overflow, and dompdf pushes the leftover rows onto a page of their own: a page with two or three rows and lots of white space is the signature. See the incident report's `rowHeight`.
- Too-high estimates waste space at the bottom of each page: the owner contact list used to fill only about three quarters of each page for that reason. Err slightly high, but measure rather than guess.
- **Measured heights** (layout's 10px DejaVu, 3px cell padding): a one-line row is about **18.2pt**, and each extra wrapped line adds about **13pt**. dompdf multiplies `line-height` by the font's own height, not by the font size, so the 1.35 line height gives roughly 13pt per line, not 10. The partial's default 20pt per row and its budgets (which treat CSS px as pt) are cautious; per-report `rowHeight` and budgets measured from a render fit far better.
- To estimate wrapping properly, give the table fixed column widths and count lines with `App\Support\PdfTextWrap` (`lineCount()` and `lineHeight()`, built on dompdf's own font metrics and line breaking) against each column's inner width, rather than a characters-per-line guess. The owner contact list is the worked example.
- To measure, render, rasterize, and find where the last row on each page ends against the bottom margin (792pt minus the bottom margin in pt). A page whose last row ends well above that, with more rows to come, has a budget or estimate that's too low.
- `keepWithNext` keeps group header rows (owner banners in the checklists) off the bottom of a page.
- When page margins change, revisit the budgets in the partial's docblock and per report.

The station inventory wraps each station in `.keep-together` (`page-break-inside: avoid`) so a short station's table doesn't split; a station longer than a page still chunks normally.

For a short, bounded table (the submission sheet's breakdown), a plain table is fine.

### CSS that works here (and doesn't)

- **Use:** block layout, tables for grids, `position: fixed` and `absolute`, `border-collapse`, `display: inline-block`, simple backgrounds and borders. `calc()` and `var()` work in dompdf 3.x.
- **Silently ignored:** `display: flex` and `grid` (fall back to block, so the layout collapses rather than erroring), `box-sizing` (the `border-box` in our resets does nothing: `width: 100%` plus padding or a border overflows), `box-shadow`, and the `break-before`/`break-after`/`break-inside` names. Only `page-break-*` works.
- **Floats** are partial. An uncleared float pushed the WFD header off-centre once; position the right-hand piece absolutely instead.
- **Fixed headers and footers must be direct children of `<body>`.** dompdf only repeats those; a nested fixed element shows once.
- **Tables:** always write `<tbody>` (without it dompdf wraps every row in its own anonymous group and memory balloons). `<thead>` repeats on each page automatically; `<tfoot>` does not. A single cell never splits across pages, so a very tall cell (a long note) overflows the footer: keep cells short or move long text out of the table, as the bulletin copy does.
- **Fonts:** set `font-family: DejaVu Sans` (or DejaVu Sans Mono) on `body`. The config's `default_font` is `serif`, which maps to Times, a core font limited to Windows-ANSI: callsigns with `Ø`, accented names and anything non-Latin come out as `?`. DejaVu covers them, in all four weights and styles. There is no emoji support and no fallback chain. Don't add `@font-face` from a URL (offline, and `enable_remote` is off). `enable_font_subsetting` is off, so each DejaVu face used embeds about 750 KB; turning it on shrinks files at some render cost on a Pi.
- **Sonar:** SonarCloud's HTML analyzer reads Blade comments and CSS comments as markup. Don't write literal tags like `<html>`, `<table>` or `<thead>` inside comments; say "the html element". Presentational layout tables trip S5256/S5257; prefer divs for footers.

## Config and security

`config/dompdf.php` is part of the hardening: `chroot` is `public_path()`, `enable_remote`, `enable_php` and `enable_javascript` are false, and `tests/Unit/DeploymentHardeningConfigTest.php` asserts it. Don't loosen any of them to make an image or font work; put the asset under `public/` (relative `src` paths resolve there) or inline it as a data URI. If you ever narrow `allowed_protocols`, keep `data://` or data URIs stop rendering. Unknown config keys are silently ignored, so check a new option's exact snake_case name against dompdf's `Options`. A config change has to hold across Docker, `deploy.sh`, `update.sh` and the Helm chart (CLAUDE.md, "Deployment paths"). `font_dir`/`font_cache` point at `storage/fonts`, which doesn't exist today because only bundled fonts are used; a custom `@font-face` would need that directory created and writable in every deploy path. Stay on dompdf 3.1.6 or later: it fixed a chroot prefix bypass and several SVG and font-loading file-read advisories (July 2026).

Each render holds the whole frame tree in memory. On a 2 GB Pi, keep the DOM lean (no nested tables, few fonts), and avoid a second render: the submission sheet already renders twice when its page total changes.

## pdf-lib forms (ICS)

The server never builds these PDFs. It returns JSON: the blank form URL and one map of FEMA field names to values per page. The browser fills, flattens and merges them.

- Field names are FEMA's own, so check them against the blank PDF (`pdfForm.getFields()`) when a value doesn't appear. `getFieldMaybe` skips unknown names silently.
- Standard PDF fonts only cover WinAnsi. Run every value through `toWinAnsi()` or pdf-lib throws on curly quotes, dashes, emoji or `Ø`.
- Rows past a form's capacity go on another filled copy of page 1; instruction pages are appended once.
- Open the tab on the click, before any `await`, or popup blockers stop it. Fall back to a download.

## Checking a change: render and look

Run from the checkout root (main checkout or demo worktree; the script picks the right container):

```bash
.claude/skills/pdf-reports/scripts/render.sh            # several pages of data per report
.claude/skills/pdf-reports/scripts/render.sh --short    # one-page versions
.claude/skills/pdf-reports/scripts/render.sh --filter equipment --dpi 70
```

It copies `scripts/RenderPdfsTest.php` into `tests/Feature` for one run (and removes it), seeds page-filling data, saves every PDF to `storage/app/pdf-render/`, rasterizes each page with Ghostscript, dumps the text per page, and builds `sheet-<report>.png` with all pages side by side. Read the sheets with the Read tool. When adding a report, add it to `RenderPdfsTest.php`.

Look at every page of every report your change can reach, long and short, and check:

- [ ] Banner meets the top edge on page 1 and on every page that starts with a banner.
- [ ] Pages without a banner start below the top margin, not at the paper edge.
- [ ] Nothing runs under the footer; the footer is clear of the paper edge.
- [ ] No page holds just a few spill-over rows (a chunk overflowed).
- [ ] Side margins match on every page; nothing touches the left or right edge.
- [ ] "Page X of Y" totals are right on every page: `grep -o 'Page [0-9]* of [0-9]*' storage/app/pdf-render/*.txt`.
- [ ] Unicode text (Ø, accents) renders, not as `?` or boxes.

Then run the targeted tests for what you touched (`tests/Feature/Equipment/EquipmentReportControllerTest.php`, `tests/Feature/Http/Controllers/ReportControllerTest.php`, `tests/Feature/Reports/WfdReportsTest.php`) and add a regression assertion where layout can be checked numerically: root margins after render, page count after `render()`, or the rendered HTML via `view(...)->render()`. There's no PDF text-extraction library in the project, and pixel layout can't be unit-tested here; that's what the render check is for.

A new report also needs: `setPaper('letter', 'portrait')` (the config default is A4), DejaVu on `body`, the scoped margin reset, an entry in `RenderPdfsTest.php`, and an inline response opened in a new tab.

When you report back, say which reports and page counts you looked at.
