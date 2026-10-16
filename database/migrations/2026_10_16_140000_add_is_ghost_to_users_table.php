<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_ghost')->default(false)->after('user_role');
        });

        DB::table('users')
            ->where('email', 'like', '%@imported.local')
            ->where('last_name', '(Imported)')
            ->update(['is_ghost' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_ghost');
        });
    }
};
