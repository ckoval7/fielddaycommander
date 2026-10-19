// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * ICS PDF - Alpine.js component that fills FEMA's fillable ICS forms.
 *
 * The server sends the blank form's URL and one map of FEMA field names to
 * values per page. For each page this loads a fresh copy of the blank form,
 * fills it, flattens it and keeps its first page. FEMA's instruction pages
 * are appended once at the end, and the merged PDF is downloaded.
 *
 * pdf-lib is loaded on first use so it stays out of the main bundle. The
 * blank forms are served by the app, so this works without internet.
 *
 * Usage in Blade:
 *   <button x-data="icsPdf('{{ route('reports.ics-205') }}')" x-on:click="download()">
 */

/**
 * Standard PDF fonts only cover WinAnsi. Swap the common typographic
 * characters for plain ones and drop anything else that would make pdf-lib
 * throw.
 */
export function toWinAnsi(value) {
    return String(value ?? '')
        .replace(/[‘’]/g, "'")
        .replace(/[“”]/g, '"')
        .replace(/[–—]/g, '-')
        .replace(/…/g, '...')
        .replace(/[^\n\r\t\x20-\x7E\xA0-\xFF]/g, '?');
}

export async function buildIcsPdf(form, fetchBytes) {
    const { PDFDocument } = await import('pdf-lib');
    const blank = await fetchBytes(form.template);
    const output = await PDFDocument.create();

    for (const fields of form.pages) {
        const page = await PDFDocument.load(blank);
        const pdfForm = page.getForm();

        for (const [name, value] of Object.entries(fields)) {
            const field = pdfForm.getFieldMaybe(name);

            if (field && typeof field.setText === 'function') {
                field.setText(toWinAnsi(value));
            }
        }

        pdfForm.flatten();
        const [filled] = await output.copyPages(page, [0]);
        output.addPage(filled);
    }

    const instructions = await PDFDocument.load(blank);
    const instructionPages = instructions.getPageIndices().slice(1);

    for (const copied of await output.copyPages(instructions, instructionPages)) {
        output.addPage(copied);
    }

    return output.save();
}

async function fetchArrayBuffer(url) {
    const response = await fetch(url, { credentials: 'same-origin' });

    if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
    }

    return response.arrayBuffer();
}

export default function icsPdf(dataUrl) {
    return {
        busy: false,

        async download() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const response = await fetch(dataUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    throw new Error(`Request failed (${response.status})`);
                }

                const form = await response.json();
                const bytes = await buildIcsPdf(form, fetchArrayBuffer);
                const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
                const link = document.createElement('a');

                link.href = url;
                link.download = form.filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 10000);
            } catch (error) {
                console.error('ICS form download failed', error);
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
