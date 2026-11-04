<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

class LineDiff
{
    /**
     * Compute a line-by-line diff between two strings.
     *
     * @return list<array{type: string, text: string}>
     */
    public static function between(string $old, string $new): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);
        $diff = [];

        $maxLen = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $maxLen; $i++) {
            $oldLine = $oldLines[$i] ?? null;
            $newLine = $newLines[$i] ?? null;

            if ($oldLine === $newLine) {
                $diff[] = ['type' => 'unchanged', 'text' => $oldLine];
            } else {
                if ($oldLine !== null) {
                    $diff[] = ['type' => 'removed', 'text' => $oldLine];
                }
                if ($newLine !== null) {
                    $diff[] = ['type' => 'added', 'text' => $newLine];
                }
            }
        }

        return $diff;
    }
}
