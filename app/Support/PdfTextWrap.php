<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Dompdf\FontMetrics;
use Dompdf\FrameReflower\Text;

/**
 * Predicts how dompdf wraps text in a table cell, so PDF templates can size
 * page chunks from real row heights instead of a fixed guess.
 *
 * Widths come from dompdf's own font metrics and the line breaking follows
 * its Text reflower: break after whitespace or dashes, let a trailing space
 * overhang, and let a word wider than the cell overflow on its own line.
 *
 * All lengths are in points. At the 96 DPI the PDFs use, 1px = 0.75pt, so a
 * CSS font-size of 10px is 7.5pt.
 */
final class PdfTextWrap
{
    public function __construct(
        private readonly FontMetrics $metrics,
        private readonly string $family = 'DejaVu Sans',
    ) {}

    /**
     * Build a measurer from a fresh dompdf instance's font metrics.
     */
    public static function forDompdf(string $family = 'DejaVu Sans'): self
    {
        return new self(app('dompdf')->getFontMetrics(), $family);
    }

    /**
     * Number of lines $text takes in a box $widthPt wide.
     */
    public function lineCount(?string $text, float $widthPt, float $sizePt = 7.5, string $weight = 'normal'): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return 1;
        }

        $font = $this->metrics->getFont($this->family, $weight);
        $spaceWidth = $this->metrics->getTextWidth(' ', $font, $sizePt);
        $parts = preg_split(Text::$_wordbreak_pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $lines = 1;
        $used = 0.0;
        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            $separator = $parts[$i + 1] ?? '';
            $word = $separator === ' ' ? $parts[$i] : $parts[$i].$separator;
            if ($word === '') {
                continue;
            }
            $wordWidth = $this->metrics->getTextWidth($word, $font, $sizePt);

            if ($used > 0 && $used + $wordWidth > $widthPt) {
                $lines++;
                $used = 0.0;
            }

            $used += $wordWidth + ($separator === ' ' ? $spaceWidth : 0.0);
        }

        return $lines;
    }

    /**
     * Height in points of one line box. dompdf multiplies a unitless
     * line-height by the font's own height (ascent plus descent), not by the
     * font size, so DejaVu Sans at 7.5pt and line-height 1.35 is about 13pt.
     */
    public function lineHeight(float $sizePt = 7.5, float $lineHeight = 1.35, string $weight = 'normal'): float
    {
        return $this->metrics->getFontHeight($this->metrics->getFont($this->family, $weight), $sizePt) * $lineHeight;
    }
}
