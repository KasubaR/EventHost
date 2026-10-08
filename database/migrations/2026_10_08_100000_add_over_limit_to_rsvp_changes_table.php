<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans/rsvp-status-changes.md Phase 5 — a host override that goes past the guest limit (or a group's seat pool) needs
 * the host's explicit tick, and is recorded as such so it is never silent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvp_changes', function (Blueprint $table) {
            $table->boolean('over_limit')->default(false)->after('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rsvp_changes', function (Blueprint $table) {
            $table->dropColumn('over_limit');
        });
    }
};
