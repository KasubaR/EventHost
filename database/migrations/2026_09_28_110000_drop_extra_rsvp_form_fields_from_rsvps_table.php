<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            $table->dropColumn(['meal_preference', 'transportation_note', 'song_request']);
        });
    }

    public function down(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            $table->string('meal_preference')->nullable();
            $table->string('transportation_note')->nullable();
            $table->string('song_request')->nullable();
        });
    }
};
