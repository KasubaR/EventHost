<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans/group-rsvp-links.md — a guest group can carry a seat pool and a shared RSVP link,
 * and every event carries the host's contact number for guests to call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_groups', function (Blueprint $table) {
            // Null seat_limit = an ordinary organising group with no link.
            $table->unsignedInteger('seat_limit')->nullable()->after('name');
            $table->string('rsvp_token', 48)->nullable()->unique()->after('seat_limit');
            $table->timestamp('rsvp_link_closed_at')->nullable()->after('rsvp_token');
        });

        Schema::table('guests', function (Blueprint $table) {
            // Set when the guest signed up through a group's shared link. Those RSVPs are
            // always held for host approval, unlike a guest the host added by hand.
            $table->timestamp('group_link_joined_at')->nullable()->after('guest_group_id');
        });

        Schema::table('events', function (Blueprint $table) {
            // Nullable in the database (existing events have none); the create/edit forms require it.
            $table->string('host_contact_phone', 40)->nullable()->after('guest_limit');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('host_contact_phone'));
        Schema::table('guests', fn (Blueprint $table) => $table->dropColumn('group_link_joined_at'));
        Schema::table('guest_groups', function (Blueprint $table) {
            $table->dropUnique(['rsvp_token']);
            $table->dropColumn(['seat_limit', 'rsvp_token', 'rsvp_link_closed_at']);
        });
    }
};
