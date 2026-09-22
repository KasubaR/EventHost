<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 item 3 of plans/public-private-portals.md: a one-time "your event
 * moved" notice for hosts whose invitation event was public before the
 * portal split existed, so it silently moved from a single "My Events" list
 * into the new Public portal at the 2026-09-22 audience backfill
 * (add_audience_to_events_table). That migration never flagged which rows it
 * touched, so this one reconstructs it: useCurrent() means every row
 * inserted from this point on defaults to "already seen" (NULL only when a
 * later UPDATE explicitly clears it — Event::dismissAudienceMigrationNotice()
 * does that on purpose in the other direction, setting it, not clearing it),
 * and the backfill below explicitly resets it to NULL only on the exact rows
 * the original migration flipped to public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('audience_migration_notice_seen_at')->nullable()->useCurrent()->after('audience');
        });

        DB::table('events')
            ->where('product_kind', '!=', 'ticketed')
            ->where('is_public', true)
            ->update(['audience_migration_notice_seen_at' => null]);
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('audience_migration_notice_seen_at');
        });
    }
};
