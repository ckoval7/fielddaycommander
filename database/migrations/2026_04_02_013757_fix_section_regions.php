<?php

use App\Models\Section;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->enum('region', ['W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9', 'W0', 'KL7', 'KH6', 'KP4', 'VE', 'DX'])->change();
        });

        Section::where('code', 'PAC')->update(['region' => 'KH6']);
        Section::where('code', 'PR')->update(['region' => 'KP4']);
        Section::where('code', 'VI')->update(['region' => 'KP4']);
    }

    public function down(): void
    {
        Section::where('code', 'PAC')->update(['region' => 'W6']);
        Section::where('code', 'PR')->update(['region' => 'W4']);
        Section::where('code', 'VI')->update(['region' => 'W4']);

        Schema::table('sections', function (Blueprint $table) {
            $table->enum('region', ['W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9', 'W0', 'KL7', 'VE', 'DX'])->change();
        });
    }
};
