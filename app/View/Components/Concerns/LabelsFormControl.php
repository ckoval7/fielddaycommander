<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components\Concerns;

/**
 * Ties a Mary form control to its label, hint and error text.
 *
 * Mary renders the visible label as a `<legend>` and the control inside an
 * empty `<label>`, so screen readers announce the field with no name, and
 * errors and hints are never linked to it. These replacements give the
 * legend, hint and first error line ids and point the control at them with
 * `aria-labelledby`, `aria-describedby` and `aria-invalid`.
 */
trait LabelsFormControl
{
    use PatchesMaryTemplate;

    public const LEGEND = '<legend class="fieldset-legend';

    public const REQUIRED_MARK = '<span class="text-error">*</span>';

    public const ERROR_LINE = '<div class="{{ $errorClass }}" x-class="text-error">';

    public const ERROR_LINE_ALT = '<div class="{{ $errorClass }}" x-classes="text-error">';

    public const HINT = '<div class="{{ $hintClass }}" x-classes="fieldset-label">{{ $hint }}</div>';

    /**
     * The text in Mary's template that opens the control, after which the
     * ARIA attributes are inserted.
     */
    abstract public static function controlNeedle(): string;

    /**
     * Whether the label is a `<legend>` that the control must reference.
     * Checkbox and toggle wrap their control in a `<label>` with the text,
     * which already names it.
     */
    public static function labelledByLegend(): bool
    {
        return true;
    }

    /**
     * Whether Mary leaves the model name out of this control's id, so two
     * controls with the same props share an id. The model name is appended
     * before the control, and the hint and error ids after it follow.
     */
    public static function appendsModelToId(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        $hasError = '$errorFieldName() && ! $omitError && $errors->has($errorFieldName())';

        // A spoken placeholder ($spokenPlaceholder, set by controls that
        // render one) is read after the hint.
        $describedBy = "trim(({$hasError} ? \$uuid.'-error ' : '').(\$hint ? \$uuid.'-hint ' : '').((\$spokenPlaceholder ?? null) !== null ? \$uuid.'-placeholder' : ''))";

        $aria = static::labelledByLegend()
            ? ' @if($label && ! ($inline ?? false) && ! $attributes->hasAny([\'aria-label\', \'aria-labelledby\'])) aria-labelledby="{{ $uuid }}-label" @endif'
                ." @if({$hasError}) aria-invalid=\"true\" @endif"
                ." @if({$describedBy} !== '') aria-describedby=\"{{ {$describedBy} }}\" @endif"
            : " @if({$hasError}) aria-invalid=\"true\" aria-describedby=\"{{ \$uuid }}-error\" @endif";

        $errorId = '@if($loop->first && ($loop->parent?->first ?? true)) id="{{ $uuid }}-error" @endif ';

        $legend = static::labelledByLegend()
            ? [self::LEGEND => '<legend id="{{ $uuid }}-label" class="fieldset-legend']
            : [];

        return $legend + [
            self::REQUIRED_MARK => '<span class="text-error" aria-hidden="true">*</span>',
            self::ERROR_LINE => '<div '.$errorId.'class="{{ $errorClass }}" x-class="text-error">',
            self::ERROR_LINE_ALT => '<div '.$errorId.'class="{{ $errorClass }}" x-classes="text-error">',
            self::HINT => '<div id="{{ $uuid }}-hint" class="{{ $hintClass }}" x-classes="fieldset-label">{{ $hint }}</div>',
            static::controlNeedle() => (static::appendsModelToId() ? '@php($uuid .= $modelName()) ' : '').static::controlNeedle().$aria,
        ];
    }
}
