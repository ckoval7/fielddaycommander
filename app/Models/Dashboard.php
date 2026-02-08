<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dashboard Model
 *
 * Represents a customizable dashboard configuration with widget layouts.
 * Each user can have multiple dashboards with one marked as default.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property array $config Array of widget configurations
 * @property bool $is_default Whether this is the user's default dashboard
 * @property string $layout_type Layout type (grid, list, etc.)
 * @property string|null $description Optional dashboard description
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read User $user
 */
class Dashboard extends Model
{
    /** @use HasFactory<\Database\Factories\DashboardFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'config',
        'is_default',
        'layout_type',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Get the user that owns this dashboard.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include default dashboards.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to dashboards for a specific user.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Validate that the config array structure is valid.
     *
     * Config should be an array of widget configurations with structure:
     * [
     *     [
     *         'id' => 'widget-1',
     *         'type' => 'stat_card',
     *         'config' => ['metric' => 'total_score'],
     *         'order' => 1,
     *         'visible' => true,
     *     ],
     *     ...
     * ]
     */
    public function hasValidConfig(): bool
    {
        if (! is_array($this->config)) {
            return false;
        }

        foreach ($this->config as $widget) {
            // Each widget must be an array
            if (! is_array($widget)) {
                return false;
            }

            // Required fields
            if (! isset($widget['id']) || ! isset($widget['type'])) {
                return false;
            }

            // Config must be an array if present
            if (isset($widget['config']) && ! is_array($widget['config'])) {
                return false;
            }

            // Order must be numeric if present
            if (isset($widget['order']) && ! is_numeric($widget['order'])) {
                return false;
            }

            // Visible must be boolean if present
            if (isset($widget['visible']) && ! is_bool($widget['visible'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get validation rules for the config structure.
     *
     * @return array<string, mixed>
     */
    public static function configValidationRules(): array
    {
        return [
            'config' => ['required', 'array'],
            'config.*.id' => ['required', 'string'],
            'config.*.type' => ['required', 'string', 'in:stat_card,chart,progress_bar,list_widget,timer,info_card,feed'],
            'config.*.config' => ['sometimes', 'array'],
            'config.*.order' => ['sometimes', 'integer', 'min:0'],
            'config.*.visible' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the number of visible widgets in this dashboard.
     */
    public function getVisibleWidgetCount(): int
    {
        return collect($this->config)
            ->filter(fn ($widget) => ($widget['visible'] ?? true))
            ->count();
    }

    /**
     * Get widgets sorted by their order property.
     */
    public function getOrderedWidgets(): array
    {
        return collect($this->config)
            ->sortBy('order')
            ->values()
            ->toArray();
    }

    /**
     * Check if this dashboard has a specific widget type.
     *
     * @param  string  $type  Widget type to check for
     */
    public function hasWidgetType(string $type): bool
    {
        return collect($this->config)
            ->contains(fn ($widget) => $widget['type'] === $type);
    }
}
