<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The host closed RSVPs by hand (plans/rsvp-deadline-moments.md Phase 2). Null = RSVP follows the deadline / event start.
     * A real instant (UTC), unlike the venue wall-clock deadline. Independent of the deadline: moving it never reopens this.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('rsvp_closed_at')->nullable()->after('invitation_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('rsvp_closed_at');
        });
    }
};
