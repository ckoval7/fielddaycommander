<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NEW_DESCRIPTION = 'Publicity received from local media';

    private const OLD_DESCRIPTION = 'Official visit by broadcast or print media representative';

    public function up(): void
    {
        DB::table('bonus_types')
            ->where('code', 'media_publicity')
            ->update([
                'trigger_type' => 'manual',
                'description' => self::NEW_DESCRIPTION,
            ]);
    }

    public function down(): void
    {
        DB::table('bonus_types')
            ->where('code', 'media_publicity')
            ->update([
                'trigger_type' => 'derived',
                'description' => self::OLD_DESCRIPTION,
            ]);
    }
};
