<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Admin-only controls — see plans/contributions.md. The host never
            // sets either of these; an admin enables the feature per event and
            // fixes the amount, same trust level as ticketing approval.
            $table->boolean('contribution_enabled')->default(false)->after('photo_wall_requires_approval');
            $table->decimal('contribution_amount', 10, 2)->nullable()->after('contribution_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['contribution_enabled', 'contribution_amount']);
        });
    }
};
