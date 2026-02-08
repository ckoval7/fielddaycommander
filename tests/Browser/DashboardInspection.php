<?php

use Illuminate\Support\Facades\Http;

test('inspect dashboard ui and collect data', function () {
    // Create a verified test user
    $user = \App\Models\User::factory()->create([
        'email_verified_at' => now(),
    ]);

    // Create a default dashboard for the user
    $dashboard = \App\Models\Dashboard::factory()->create([
        'user_id' => $user->id,
        'is_default' => true,
        'config' => [
            [
                'id' => 'welcome-widget',
                'type' => 'info_card',
                'config' => ['title' => 'Welcome'],
                'visible' => true,
                'order' => 1,
            ],
        ],
    ]);

    // Make request as authenticated user using test client
    $response = $this->actingAs($user)->get('/dashboard');

    // Handle redirect or response
    if ($response->status() === 302) {
        // Follow redirect
        $redirectUrl = $response->headers->get('Location');
        $response = $this->actingAs($user)->get($redirectUrl);
    }

    $html = $response->getContent() ?? '';

    // Extract page title
    preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches);
    $title = $matches[1] ?? 'Dashboard';

    // Extract all headings
    $h1_texts = [];
    preg_match_all('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $matches);
    $h1_texts = array_map('trim', array_map('html_entity_decode', $matches[1] ?? []));

    $h2_texts = [];
    preg_match_all('/<h2[^>]*>([^<]+)<\/h2>/i', $html, $matches);
    $h2_texts = array_map('trim', array_map('html_entity_decode', $matches[1] ?? []));

    $h3_texts = [];
    preg_match_all('/<h3[^>]*>([^<]+)<\/h3>/i', $html, $matches);
    $h3_texts = array_map('trim', array_map('html_entity_decode', $matches[1] ?? []));

    // Find widget/card elements
    $widget_classes = [];
    if (preg_match_all('/class="([^"]*(?:card|widget|panel)[^"]*)"/i', $html, $matches)) {
        foreach (array_slice($matches[1], 0, 10) as $class_str) {
            $widget_classes[] = [
                'classes' => $class_str,
                'count' => count(preg_split('/\s+/', $class_str)),
            ];
        }
    }

    // Find Livewire wire:id elements
    $livewire_wire_ids = [];
    if (preg_match_all('/wire:id="([^"]+)"/', $html, $matches)) {
        $livewire_wire_ids = array_unique($matches[1]);
    }

    // Parse component names from Livewire initialization
    $component_names = [];
    if (preg_match_all('/@livewire\([\'"]([^\'"]+)[\'"]/', $html, $matches)) {
        $component_names = array_merge($component_names, $matches[1]);
    }
    // Also look for Livewire.mount() calls in scripts
    if (preg_match_all('/Livewire\.mount\([\'"]([^\'"]+)[\'"]/', $html, $matches)) {
        $component_names = array_merge($component_names, $matches[1]);
    }
    $component_names = array_unique($component_names);

    // Find all button labels
    $buttons = [];
    if (preg_match_all('/<button[^>]*>([^<]+)<\/button>/i', $html, $matches)) {
        $buttons = array_map('trim', array_filter(
            array_map('html_entity_decode', $matches[1]),
            function($b) { return strlen($b) > 0; }
        ));
    }

    // Count form elements
    $input_count = substr_count($html, '<input');
    $textarea_count = substr_count($html, '<textarea');
    $select_count = substr_count($html, '<select');

    // Compile inspection data
    $inspection_data = [
        'timestamp' => now()->toIso8601String(),
        'page_title' => $title,
        'page_url' => '/dashboard',
        'html_length' => strlen($html),
        'headings_h1' => $h1_texts,
        'headings_h2' => $h2_texts,
        'headings_h3' => $h3_texts,
        'widget_elements_found' => count($widget_classes),
        'widget_classes_sample' => array_slice($widget_classes, 0, 5),
        'livewire_wire_ids' => array_slice($livewire_wire_ids, 0, 10),
        'livewire_components' => $component_names,
        'total_buttons' => count($buttons),
        'button_labels' => array_slice(array_unique($buttons), 0, 10),
        'form_elements' => [
            'input_count' => $input_count,
            'textarea_count' => $textarea_count,
            'select_count' => $select_count,
        ],
    ];

    // Build markdown report
    $report = "# Dashboard Inspection Report\n\n";
    $report .= "**Generated:** " . $inspection_data['timestamp'] . "\n\n";

    $report .= "## Overview\n";
    $report .= "- **URL:** " . $inspection_data['page_url'] . "\n";
    $report .= "- **Page Title:** " . htmlspecialchars($inspection_data['page_title']) . "\n";
    $report .= "- **HTML Size:** " . $inspection_data['html_length'] . " bytes\n\n";

    $report .= "## Page Structure\n";
    $report .= "### Headings\n";

    $report .= "**H1 Headings (" . count($inspection_data['headings_h1']) . "):**\n";
    if (empty($inspection_data['headings_h1'])) {
        $report .= "- None found\n";
    } else {
        foreach ($inspection_data['headings_h1'] as $h1) {
            $report .= "- " . htmlspecialchars($h1) . "\n";
        }
    }

    $report .= "\n**H2 Headings (" . count($inspection_data['headings_h2']) . "):**\n";
    if (empty($inspection_data['headings_h2'])) {
        $report .= "- None found\n";
    } else {
        foreach ($inspection_data['headings_h2'] as $h2) {
            $report .= "- " . htmlspecialchars($h2) . "\n";
        }
    }

    $report .= "\n**H3 Headings (" . count($inspection_data['headings_h3']) . "):**\n";
    if (empty($inspection_data['headings_h3'])) {
        $report .= "- None found\n";
    } else {
        foreach ($inspection_data['headings_h3'] as $h3) {
            $report .= "- " . htmlspecialchars($h3) . "\n";
        }
    }

    $report .= "\n## UI Components & Widgets\n";
    $report .= "- **Widget/Card elements found:** " . $inspection_data['widget_elements_found'] . "\n";
    if (!empty($inspection_data['widget_classes_sample'])) {
        $report .= "- **Sample widget classes:**\n";
        foreach ($inspection_data['widget_classes_sample'] as $idx => $widget) {
            $report .= "  " . ($idx + 1) . ". `" . htmlspecialchars($widget['classes']) . "`\n";
        }
    }

    $report .= "\n## Livewire Components\n";
    $report .= "- **Components found via @livewire/Livewire.mount:** " . count($inspection_data['livewire_components']) . "\n";
    if (!empty($inspection_data['livewire_components'])) {
        foreach ($inspection_data['livewire_components'] as $component) {
            $report .= "  - " . htmlspecialchars($component) . "\n";
        }
    }
    $report .= "\n- **Elements with wire:id:** " . count(array_filter($inspection_data['livewire_wire_ids'])) . "\n";
    if (!empty($inspection_data['livewire_wire_ids'])) {
        foreach ($inspection_data['livewire_wire_ids'] as $wire_id) {
            if (!empty($wire_id)) {
                $report .= "  - `" . htmlspecialchars($wire_id) . "`\n";
            }
        }
    }

    $report .= "\n## Interactive Elements\n";
    $report .= "- **Total buttons:** " . $inspection_data['total_buttons'] . "\n";
    if (!empty($inspection_data['button_labels'])) {
        $report .= "- **Button labels (sample):**\n";
        foreach ($inspection_data['button_labels'] as $label) {
            $report .= "  - " . htmlspecialchars($label) . "\n";
        }
    }

    $report .= "\n## Form Elements\n";
    $report .= "- **Input fields:** " . $inspection_data['form_elements']['input_count'] . "\n";
    $report .= "- **Textareas:** " . $inspection_data['form_elements']['textarea_count'] . "\n";
    $report .= "- **Select dropdowns:** " . $inspection_data['form_elements']['select_count'] . "\n";

    $report .= "\n## Technical Stack\n";
    $report .= "- **Frontend Framework:** Livewire 4 + Alpine.js 3\n";
    $report .= "- **CSS Framework:** Tailwind CSS v4 + daisyUI v5\n";
    $report .= "- **Backend:** Laravel 12\n";

    $report .= "\n## Raw Inspection Data (JSON)\n";
    $report .= "```json\n" . json_encode($inspection_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n";

    // Save reports
    file_put_contents(
        base_path('dashboard-inspection-report.md'),
        $report
    );

    file_put_contents(
        base_path('dashboard-inspection-data.json'),
        json_encode($inspection_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    // Verify files were created
    expect(file_exists(base_path('dashboard-inspection-report.md')))->toBeTrue();
    expect(file_exists(base_path('dashboard-inspection-data.json')))->toBeTrue();
});
