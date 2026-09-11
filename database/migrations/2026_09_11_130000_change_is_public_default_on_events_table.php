<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public invitations are now Base-and-above (User::canMakeEventsPublic()) —
     * the column default flips to match "invite-only by default" for any row
     * that skips the form's explicit value (a factory, a seeder, a tinker
     * insert). Existing events keep whatever is_public they already have;
     * this only changes what a future bare INSERT gets.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_public')->default(true)->change();
        });
    }
};
