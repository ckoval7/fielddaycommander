<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Band extends Model
{
    /** @use HasFactory<\Database\Factories\BandFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'meters',
        'frequency_mhz',
        'is_hf',
        'is_vhf_uhf',
        'is_satellite',
        'allowed_fd',
        'allowed_wfd',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_hf' => 'boolean',
            'is_vhf_uhf' => 'boolean',
            'is_satellite' => 'boolean',
            'allowed_fd' => 'boolean',
            'allowed_wfd' => 'boolean',
        ];
    }
}
