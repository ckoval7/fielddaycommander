<?php

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class WidgetConfigurator extends Component
{
    public string $widgetType = '';

    public array $widgetConfig = [];

    public bool $showModal = false;

    public string $mode = 'add';

    public int $colSpan = 1;

    public int $rowSpan = 2;

    public function mount(?string $widgetType = null, ?array $config = []): void
    {
        if ($widgetType) {
            $this->setWidgetType($widgetType);
            $this->widgetConfig = $config ?? [];
        }
    }

    public function setWidgetType(string $type): void
    {
        $this->widgetType = $type;
        $schema = $this->getSchema();

        // Initialize config with defaults from schema
        $defaults = collect($schema)
            ->mapWithKeys(function ($field, $key) {
                return [$key => $field['default'] ?? null];
            })
            ->toArray();

        $this->widgetConfig = array_merge($defaults, $this->widgetConfig);
    }

    public function save(): void
    {
        $rules = $this->buildValidationRules();

        if (! empty($rules)) {
            $this->validate($rules, $this->messages());
        }

        $this->dispatch('widget-configured',
            type: $this->widgetType,
            config: $this->widgetConfig,
            mode: $this->mode,
            colSpan: $this->colSpan,
            rowSpan: $this->rowSpan
        );

        $this->cancel();
    }

    public function cancel(): void
    {
        $this->showModal = false;
        $this->reset(['widgetType', 'widgetConfig', 'mode', 'colSpan', 'rowSpan']);
    }

    /**
     * Handle the open-widget-configurator event from the DashboardEditor.
     */
    #[On('open-widget-configurator')]
    public function handleOpenConfigurator(string $mode = 'add', ?string $widgetType = null, ?array $config = [], int $colSpan = 1, int $rowSpan = 2): void
    {
        $this->colSpan = $colSpan;
        $this->rowSpan = $rowSpan;
        $this->openModal($mode, $widgetType, $config);
    }

    public function openModal(string $mode = 'add', ?string $widgetType = null, ?array $config = []): void
    {
        $this->mode = $mode;

        if ($widgetType) {
            $this->setWidgetType($widgetType);
            $this->widgetConfig = $config ?? [];
        }

        $this->showModal = true;
    }

    public function getSchema(): array
    {
        if (! $this->widgetType) {
            return [];
        }

        return config("dashboard.widget_types.{$this->widgetType}.config_schema", []);
    }

    protected function buildValidationRules(): array
    {
        $rules = [];
        $schema = $this->getSchema();

        foreach ($schema as $fieldName => $fieldConfig) {
            $fieldRules = [];

            if ($fieldConfig['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            // Add type-specific validation
            switch ($fieldConfig['type']) {
                case 'select':
                    if (isset($fieldConfig['options'])) {
                        $allowedValues = array_keys($fieldConfig['options']);
                        $fieldRules[] = 'in:'.implode(',', $allowedValues);
                    }
                    break;
                case 'number':
                    $fieldRules[] = 'numeric';
                    if (isset($fieldConfig['min'])) {
                        $fieldRules[] = 'min:'.$fieldConfig['min'];
                    }
                    if (isset($fieldConfig['max'])) {
                        $fieldRules[] = 'max:'.$fieldConfig['max'];
                    }
                    break;
                case 'toggle':
                case 'checkbox':
                    $fieldRules[] = 'boolean';
                    break;
                case 'text':
                default:
                    $fieldRules[] = 'string';
                    break;
            }

            $rules["widgetConfig.{$fieldName}"] = $fieldRules;
        }

        return $rules;
    }

    protected function messages(): array
    {
        $messages = [];
        $schema = $this->getSchema();

        foreach ($schema as $fieldName => $fieldConfig) {
            $label = $fieldConfig['label'] ?? ucfirst(str_replace('_', ' ', $fieldName));

            $messages["widgetConfig.{$fieldName}.required"] = "The {$label} field is required.";
            $messages["widgetConfig.{$fieldName}.in"] = "Please select a valid {$label}.";
            $messages["widgetConfig.{$fieldName}.numeric"] = "The {$label} must be a number.";
            $messages["widgetConfig.{$fieldName}.boolean"] = "The {$label} must be true or false.";

            if (isset($fieldConfig['min'])) {
                $messages["widgetConfig.{$fieldName}.min"] = $fieldConfig['min_message'] ?? "The {$label} must be at least {$fieldConfig['min']}.";
            }

            if (isset($fieldConfig['max'])) {
                $messages["widgetConfig.{$fieldName}.max"] = $fieldConfig['max_message'] ?? "The {$label} must not exceed {$fieldConfig['max']}.";
            }
        }

        return $messages;
    }

    public function getAvailableWidgetTypesProperty(): array
    {
        return collect(config('dashboard.widget_types', []))
            ->map(function ($config, $key) {
                return [
                    'value' => $key,
                    'label' => $config['name'] ?? ucfirst($key),
                    'description' => $config['description'] ?? '',
                    'icon' => $config['icon'] ?? 'o-cube',
                ];
            })
            ->toArray();
    }

    public function render(): View
    {
        return view('livewire.dashboard.widget-configurator');
    }
}
