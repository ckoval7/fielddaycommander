// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * ICS PDF - Alpine.js component that fills FEMA's fillable ICS forms.
 *
 * The server sends the blank form's URL and one map of FEMA field names to
 * values per page. For each page this loads a fresh copy of the blank form,
 * fills it, flattens it and keeps its first page. FEMA's instruction pages
 * are appended once at the end, and the merged PDF opens in a new tab.
 *
 * The tab is opened on the click itself, before any await, so popup blockers
 * treat it as user-initiated. If the browser blocks it anyway, the PDF is
 * downloaded instead.
 *
 * pdf-lib is loaded on first use so it stays out of the main bundle. The
 * blank forms are served by the app, so this works without internet.
 *
 * Usage in Blade:
 *   <button x-data="icsPdf('{{ route('reports.ics-205') }}')" x-on:click="openPdf()">
 */

/**
 * Standard PDF fonts only cover WinAnsi. Swap the common typographic
 * characters for plain ones and drop anything else that would make pdf-lib
 * throw.
 */
function toWinAnsi(value) {
    return String(value ?? '')
        .replaceAll(/[‘’]/g, "'")
        .replaceAll(/[“”]/g, '"')
        .replaceAll(/[–—]/g, '-')
        .replaceAll('…', '...')
        .replaceAll(/[^\n\r\t\x20-\x7E\xA0-\xFF]/g, '?');
}

/**
 * Smallest size a long value is shrunk to. Past this it's cut off at the
 * field's edge, as it would be on paper.
 */
const MIN_FONT_SIZE = 5;

/**
 * Shrink a single-line field's text when it's wider than the field. FEMA's
 * fields have a fixed size (10pt on the ICS-205A), so a long position or
 * contact would otherwise be cut off. Multi-line fields wrap instead, and
 * fields set to auto size already fit.
 */
function shrinkToFit(field, text, font) {
    if (text === '' || field.isMultiline()) {
        return;
    }

    const size = Number(/([\d.]+)\s+Tf/.exec(field.acroField.getDefaultAppearance() ?? '')?.[1] ?? 0);
    const width = field.acroField.getWidgets()[0]?.getRectangle().width ?? 0;
    const available = width - 4;
    const needed = font.widthOfTextAtSize(text, size);

    if (size > 0 && available > 0 && needed > available) {
        field.setFontSize(Math.max(MIN_FONT_SIZE, Math.floor((size * available / needed) * 10) / 10));
    }
}

async function buildIcsPdf(form) {
    const { PDFDocument, StandardFonts } = await import('pdf-lib');
    const blank = await (await fetchOk(form.template)).arrayBuffer();
    const output = await PDFDocument.create();

    const pages = await Promise.all(form.pages.map(async (fields) => {
        const page = await PDFDocument.load(blank);
        const pdfForm = page.getForm();
        const helvetica = await page.embedFont(StandardFonts.Helvetica);

        for (const [name, value] of Object.entries(fields)) {
            const field = pdfForm.getFieldMaybe(name);

            if (field && typeof field.setText === 'function') {
                const text = toWinAnsi(value);

                field.setText(text);
                shrinkToFit(field, text, helvetica);
            }
        }

        pdfForm.flatten();

        return page;
    }));

    const filledPages = await Promise.all(pages.map((page) => output.copyPages(page, [0])));
    filledPages.forEach(([filled]) => output.addPage(filled));

    const instructions = await PDFDocument.load(blank);
    const instructionPages = await output.copyPages(instructions, instructions.getPageIndices().slice(1));
    instructionPages.forEach((copied) => output.addPage(copied));

    return output.save();
}

async function fetchOk(url, headers = {}) {
    const response = await fetch(url, { credentials: 'same-origin', headers });

    if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
    }

    return response;
}

function saveAs(url, filename) {
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

export default function icsPdf(dataUrl) {
    return {
        busy: false,

        async openPdf() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            const tab = window.open('', '_blank');

            try {
                const form = await (await fetchOk(dataUrl, { Accept: 'application/json' })).json();
                const bytes = await buildIcsPdf(form);
                const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));

                if (tab) {
                    // The tab's PDF viewer reads the blob for saving and
                    // printing, so the URL lives as long as this page does.
                    tab.location.href = url;
                } else {
                    saveAs(url, form.filename);
                    setTimeout(() => URL.revokeObjectURL(url), 10000);
                }
            } catch (error) {
                console.error('ICS form build failed', error);
                tab?.close();
                globalThis.Livewire?.dispatch('toast', {
                    title: 'Could not build the form',
                    description: 'Try again. If it keeps failing, reload the page.',
                    type: 'error',
                });
            } finally {
                this.busy = false;
            }
        },
    };
}
