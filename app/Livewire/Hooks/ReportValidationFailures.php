<?php

namespace App\Livewire\Hooks;

use Illuminate\Validation\ValidationException;
use Livewire\ComponentHook;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Throwable;

/**
 * Flags Livewire actions that end in a validation failure so the frontend can
 * surface a floating error summary and scroll to the first invalid field.
 *
 * Only method calls (wire:submit, wire:click, $wire.save(), ...) are tracked;
 * live property updates stay silent so typing never yanks the viewport.
 */
class ReportValidationFailures extends ComponentHook
{
    public const EFFECT = 'validationErrors';

    /**
     * @param  array<int, mixed>  $params
     */
    public function call(string $method, array $params, callable $returnEarly, mixed $metadata, mixed $componentContext): void
    {
        if (str_starts_with($method, '$') || str_starts_with($method, '__')) {
            return;
        }

        $this->storeSet('errorsBeforeCall', $this->currentErrors());
    }

    public function exception(Throwable $e, callable $stopPropagation): void
    {
        if ($e instanceof ValidationException) {
            $this->storeSet('threwValidationException', true);
        }
    }

    public function dehydrate(ComponentContext $context): void
    {
        if (! $this->storeHas('errorsBeforeCall')) {
            return;
        }

        $errors = $this->currentErrors();

        if ($errors === []) {
            return;
        }

        $addedErrors = $errors !== $this->storeGet('errorsBeforeCall');

        if ($this->storeHas('threwValidationException') || $addedErrors) {
            $context->addEffect(self::EFFECT, $errors);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function currentErrors(): array
    {
        return $this->component->getErrorBag()->toArray();
    }
}
