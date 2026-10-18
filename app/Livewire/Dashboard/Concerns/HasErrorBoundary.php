<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Concerns;

trait HasErrorBoundary
{
    public bool $hasError = false;

    public string $errorMessage = '';

    /**
     * Mark this widget as errored with a fallback view.
     * Call this from computed properties or action handlers
     * when an unexpected error occurs.
     */
    protected function handleWidgetError(\Throwable $e): void
    {
        $this->hasError = true;
        $this->errorMessage = $e->getMessage();
        report($e);
    }

    /**
     * Get the human-readable widget name for error display.
     */
    protected function getWidgetName(): string
    {
        $className = class_basename(static::class);

        return preg_replace('/([a-z])([A-Z])/', '$1 $2', $className);
    }
}
