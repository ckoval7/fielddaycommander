<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Organization model representing a club or organization.
 *
 * @property int $id
 * @property string $name
 * @property string $callsign
 * @property string $email
 * @property string $phone
 * @property string $address
 * @property string|null $logo_path
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Organization extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'callsign',
        'email',
        'phone',
        'address',
        'logo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    /**
     * Get equipment owned by this organization.
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'owner_organization_id');
    }

    // Scopes
    /**
     * Filter to only active organizations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
