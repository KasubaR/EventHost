<?php

use App\Enums\PublicRegistrationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans/public-private-portals.md Phase 4c — free-registration (public +
 * invitation) events are admin-approved and admin-quoted, the same posture
 * as ticketed events, instead of costing an event credit. Mirrors the
 * ticketing_status cluster of columns added for ticketed events
 * (2026_08_16_140000_add_ticketing_to_events_and_create_ticket_types.php)
 * almost column-for-column, with a quote amount/paid-at pair standing in for
 * ticketing's commission_mode/agreed_payout_on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('public_registration_status', 20)
                ->default(PublicRegistrationStatus::NotApplicable->value)
                ->after('audience');
            $table->timestamp('public_registration_submitted_at')->nullable()->after('public_registration_status');
            $table->timestamp('public_registration_reviewed_at')->nullable()->after('public_registration_submitted_at');
            $table->foreignId('public_registration_reviewed_by')->nullable()->after('public_registration_reviewed_at')
                ->constrained('admins')->nullOnDelete();
            $table->text('public_registration_rejection_note')->nullable()->after('public_registration_reviewed_by');
            // Set by the admin at approval time — a one-off price for this
            // specific event, not a reissuable quote row (see the plan's own
            // note on why this isn't a CustomQuote-style table).
            $table->decimal('public_registration_quote_amount', 10, 2)->nullable()->after('public_registration_rejection_note');
            $table->timestamp('public_registration_quote_paid_at')->nullable()->after('public_registration_quote_amount');

            $table->index(['audience', 'public_registration_status']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['audience', 'public_registration_status']);
            $table->dropConstrainedForeignId('public_registration_reviewed_by');
            $table->dropColumn([
                'public_registration_status',
                'public_registration_submitted_at',
                'public_registration_reviewed_at',
                'public_registration_rejection_note',
                'public_registration_quote_amount',
                'public_registration_quote_paid_at',
            ]);
        });
    }
};
