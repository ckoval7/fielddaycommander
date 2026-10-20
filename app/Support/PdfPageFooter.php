<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Barryvdh\DomPDF\PDF;

/**
 * Draws the shared report footer (a rule, a left label and a right label with
 * "Page X of Y") on every page of a dompdf report, in the page's bottom margin.
 *
 * It draws on the canvas after render() because that is the only way to get a
 * page total: dompdf substitutes {PAGE_NUM} and {PAGE_COUNT} at output, while
 * CSS counter(pages) is not implemented and always renders 0.
 */
final class PdfPageFooter
{
    private const FONT_SIZE = 9;

    private const MARGIN_X = 48.0;

    /** @var array{0: float, 1: float, 2: float} #475569 */
    private const TEXT_COLOR = [0.28, 0.34, 0.41];

    /** @var array{0: float, 1: float, 2: float} #1e3ea8 */
    private const RULE_COLOR = [0.118, 0.243, 0.659];

    private function __construct() {}

    /**
     * Render the PDF and draw the footer on every page.
     *
     * The right label may contain {PAGE_NUM} and {PAGE_COUNT}.
     */
    public static function render(PDF $pdf, string $left, string $right): void
    {
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $pageWidth = $canvas->get_width();
        $baselineY = $canvas->get_height() - 36;
        $ruleY = $baselineY - 8;

        // The placeholders are substituted at output, but the x-position is fixed
        // now. Measure against the widest substituted form (a page number never
        // has more digits than the total) so the right edge lands correctly.
        $pageCount = max(1, $canvas->get_page_count());
        $rightForMeasurement = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], (string) $pageCount, $right);
        $rightWidth = $fontMetrics->getTextWidth($rightForMeasurement, $font, self::FONT_SIZE);

        $canvas->page_line(self::MARGIN_X, $ruleY, $pageWidth - self::MARGIN_X, $ruleY, self::RULE_COLOR, 1.5);
        $canvas->page_text(self::MARGIN_X, $baselineY, $left, $font, self::FONT_SIZE, self::TEXT_COLOR);
        $canvas->page_text($pageWidth - self::MARGIN_X - $rightWidth, $baselineY, $right, $font, self::FONT_SIZE, self::TEXT_COLOR);
    }
}
