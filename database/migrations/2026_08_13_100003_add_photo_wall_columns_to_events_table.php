<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('photo_wall_enabled')->default(true)->after('show_guest_list');
            $table->boolean('photo_wall_requires_approval')->default(false)->after('photo_wall_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['photo_wall_enabled', 'photo_wall_requires_approval']);
        });
    }
};
