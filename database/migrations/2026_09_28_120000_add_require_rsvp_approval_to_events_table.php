<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans/rsvp-host-approval.md — opt-in per event, off by default. When on, an
 * Accepted RSVP is held pending host review instead of immediately sending
 * the guest's confirmation + entry pass. See RsvpSubmissionService::submit()
 * and CommunicationService::dispatchRsvpNotifications() for where this is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('require_rsvp_approval')->default(false)->after('allow_plus_one');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('require_rsvp_approval');
        });
    }
};
