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

async function buildIcsPdf(form) {
    const { PDFDocument } = await import('pdf-lib');
    const blank = await (await fetchOk(form.template)).arrayBuffer();
    const output = await PDFDocument.create();

    const pages = await Promise.all(form.pages.map(async (fields) => {
        const page = await PDFDocument.load(blank);
        const pdfForm = page.getForm();

        for (const [name, value] of Object.entries(fields)) {
            const field = pdfForm.getFieldMaybe(name);

            if (field && typeof field.setText === 'function') {
                field.setText(toWinAnsi(value));
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
