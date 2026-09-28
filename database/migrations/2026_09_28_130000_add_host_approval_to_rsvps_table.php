<?php

use App\Enums\RsvpApprovalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans/rsvp-host-approval.md — mirrors the ticketing_status cluster on
 * events (2026_08_16_140000_add_ticketing_to_events_and_create_ticket_types.php)
 * and the public_registration_status cluster
 * (2026_09_22_150000_add_public_registration_approval_to_events_table.php)
 * almost column-for-column. host_reviewed_by points at users, not admins —
 * this is the event's own host reviewing their own guest's RSVP, not
 * EventHost staff reviewing a host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            $table->string('host_approval_status', 20)
                ->default(RsvpApprovalStatus::NotRequired->value)
                ->after('message');
            $table->timestamp('host_reviewed_at')->nullable()->after('host_approval_status');
            $table->foreignId('host_reviewed_by')->nullable()->after('host_reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('host_rejection_note')->nullable()->after('host_reviewed_by');

            $table->index(['event_id', 'host_approval_status']);
        });
    }

    public function down(): void
    {
        Schema::table('rsvps', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'host_approval_status']);
            $table->dropConstrainedForeignId('host_reviewed_by');
            $table->dropColumn([
                'host_approval_status',
                'host_reviewed_at',
                'host_rejection_note',
            ]);
        });
    }
};
