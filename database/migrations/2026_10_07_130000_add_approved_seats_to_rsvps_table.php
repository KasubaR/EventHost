<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * plans/rsvp-status-changes.md Phase 3 — approval follows seats, not answers. `approved_seats` is how many seats the
 * host has approved for this guest. It survives a Declined/Maybe round trip, so coming back to Accepted within it needs
 * no new review, and a plus-one added later only puts the extra seat in front of the host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            $table->unsignedSmallInteger('approved_seats')->nullable()->after('host_rejection_note');
        });

        // Every RSVP the host already approved was approved for the seats it holds today.
        DB::table('rsvps')
            ->where('host_approval_status', 'approved')
            ->update(['approved_seats' => DB::raw('attendee_count')]);
    }

    public function down(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            $table->dropColumn('approved_seats');
        });
    }
};
