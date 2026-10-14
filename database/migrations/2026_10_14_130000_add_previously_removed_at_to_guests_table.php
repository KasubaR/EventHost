<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set when a guest signed themselves up (open form or group link) with an email or phone that matches someone the host had
     * removed (plans/guest-deletion-gaps.md Phase 6). Such a guest's RSVP is always held for the host, whatever the event's own
     * approval switch says, and the guest list labels them. The host's own add and an import never set it.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table): void {
            $table->timestamp('previously_removed_at')->nullable()->after('group_link_joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table): void {
            $table->dropColumn('previously_removed_at');
        });
    }
};
