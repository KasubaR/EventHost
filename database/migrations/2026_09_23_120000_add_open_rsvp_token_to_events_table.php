<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Set only when the host enables the shared invite link on a
            // private (invite-only) event — lets guests self-RSVP from one
            // link instead of the host adding every guest individually.
            // Null means the feature is off; the value is a bearer token
            // (URL: /join/{token}), unrelated to guests.invitation_token.
            $table->string('open_rsvp_token', 48)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('open_rsvp_token');
        });
    }
};
