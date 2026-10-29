<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

/**
 * Strips template indentation and comments from rendered HTML.
 *
 * Any whitespace run that contains a line break becomes a single line break,
 * which renders the same under normal white-space rules, and keeps line
 * comments in inline Alpine expressions working. HTML comments are dropped,
 * except Livewire's `<!--[if …]>` morph markers. Text that renders its
 * whitespace is left alone: <pre>, <textarea>, <script>, <style>, and
 * elements with a whitespace-pre* class or a `white-space: pre*` style.
 *
 * Applied to Livewire component renders and full HTML pages alike, and
 * idempotent, so the first page load and later updates match and morphs
 * don't churn on whitespace.
 */
class HtmlMinifier
{
    /**
     * A tag's attributes, consuming quoted values whole since Alpine
     * expressions can contain `>`.
     */
    private const ATTRIBUTES = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    /**
     * Comments, then blocks whose whitespace must survive.
     */
    private const TOKENS = '~<!--.*?-->'
        .'|<(pre|textarea|script|style)\b'.self::ATTRIBUTES.'>.*?</\1\s*>'
        .'|<([a-zA-Z][\w-]*)\b(?=[^>]*?(?:whitespace-pre|white-space:\s*pre))'.self::ATTRIBUTES.'>.*?</\2\s*>~is';

    public static function minify(string $html): string
    {
        $withoutComments = self::rewrite($html, fn (string $text): string => $text, function (string $token): string {
            return str_starts_with($token, '<!--') && ! str_starts_with($token, '<!--[') ? '' : $token;
        });

        $squeezed = self::rewrite($withoutComments, fn (string $text): string => preg_replace('~[ \t\r]*\n\s*~', "\n", $text) ?? $text, fn (string $token): string => $token);

        // A morph marker at the start of a line splits its indentation off
        // from the line break; drop that indentation too.
        return preg_replace_callback(
            '~\n(?:<!--\[if [^\]]*\]><!\[endif\]-->[ \t]*)+~',
            fn (array $match): string => preg_replace('~-->[ \t]+~', '-->', $match[0]) ?? $match[0],
            $squeezed,
        ) ?? $squeezed;
    }

    /**
     * Rewrite the text between tokens and the tokens themselves.
     *
     * @param  callable(string): string  $text
     * @param  callable(string): string  $token
     */
    private static function rewrite(string $html, callable $text, callable $token): string
    {
        if (preg_match_all(self::TOKENS, $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $html;
        }

        $rewritten = '';
        $position = 0;

        foreach ($matches[0] as [$match, $offset]) {
            $rewritten .= $text(substr($html, $position, $offset - $position)).$token($match);
            $position = $offset + strlen($match);
        }

        return $rewritten.$text(substr($html, $position));
    }
}
