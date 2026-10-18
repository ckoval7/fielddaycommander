<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->randomElement([
                'My Dashboard',
                'Field Day Dashboard',
                'Operations Dashboard',
                'Quick View',
                'Main Dashboard',
            ]),
            'config' => $this->generateDefaultConfig(),
            'is_default' => false,
            'layout_type' => 'grid',
            'description' => fake()->optional(0.6)->sentence(),
        ];
    }

    /**
     * Generate a default widget configuration array.
     */
    protected function generateDefaultConfig(): array
    {
        return [
            [
                'id' => 'widget-1',
                'type' => 'stat_card',
                'config' => ['metric' => 'total_score'],
                'order' => 1,
                'visible' => true,
            ],
            [
                'id' => 'widget-2',
                'type' => 'stat_card',
                'config' => ['metric' => 'qso_count'],
                'order' => 2,
                'visible' => true,
            ],
            [
                'id' => 'widget-3',
                'type' => 'timer',
                'config' => ['timer_type' => 'event_countdown'],
                'order' => 3,
                'visible' => true,
            ],
            [
                'id' => 'widget-4',
                'type' => 'progress_bar',
                'config' => ['metric' => 'next_milestone'],
                'order' => 4,
                'visible' => true,
            ],
            [
                'id' => 'widget-5',
                'type' => 'chart',
                'config' => [
                    'chart_type' => 'bar',
                    'data_source' => 'qsos_per_hour',
                ],
                'order' => 5,
                'visible' => true,
            ],
            [
                'id' => 'widget-6',
                'type' => 'list',
                'config' => ['list_type' => 'recent_contacts'],
                'order' => 6,
                'visible' => true,
            ],
        ];
    }

    /**
     * Indicate that this dashboard is the user's default.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Create a dashboard with minimal widgets.
     */
    public function minimal(): static
    {
        return $this->state(fn (array $attributes) => [
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_score'],
                    'order' => 1,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-2',
                    'type' => 'timer',
                    'config' => ['timer_type' => 'event_countdown'],
                    'order' => 2,
                    'visible' => true,
                ],
            ],
        ]);
    }

    /**
     * Create a TV dashboard with large-format widgets.
     */
    public function tv(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'TV Dashboard',
            'layout_type' => 'tv',
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_score'],
                    'order' => 1,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-2',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'qso_count'],
                    'order' => 2,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-3',
                    'type' => 'timer',
                    'config' => ['timer_type' => 'event_countdown'],
                    'order' => 3,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-4',
                    'type' => 'progress_bar',
                    'config' => ['metric' => 'next_milestone'],
                    'order' => 4,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-5',
                    'type' => 'chart',
                    'config' => [
                        'chart_type' => 'bar',
                        'data_source' => 'qsos_per_hour',
                    ],
                    'order' => 5,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-6',
                    'type' => 'chart',
                    'config' => [
                        'chart_type' => 'bar',
                        'data_source' => 'qsos_per_band',
                    ],
                    'order' => 6,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-7',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'sections_worked'],
                    'order' => 7,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-8',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'operators_count'],
                    'order' => 8,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-9',
                    'type' => 'list',
                    'config' => ['list_type' => 'recent_contacts'],
                    'order' => 9,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-10',
                    'type' => 'feed',
                    'config' => ['feed_type' => 'all_activity'],
                    'order' => 10,
                    'visible' => true,
                ],
            ],
        ]);
    }

    /**
     * Create a dashboard with some widgets hidden.
     */
    public function withHiddenWidgets(): static
    {
        return $this->state(function (array $attributes) {
            $config = $this->generateDefaultConfig();
            // Hide the last 2 widgets
            $config[4]['visible'] = false;
            $config[5]['visible'] = false;

            return ['config' => $config];
        });
    }

    /**
     * Create a dashboard with an empty config (no widgets).
     */
    public function empty(): static
    {
        return $this->state(fn (array $attributes) => [
            'config' => [],
        ]);
    }
}
