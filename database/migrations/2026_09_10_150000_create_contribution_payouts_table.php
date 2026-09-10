<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-recorded disbursement — manual, no Lenco call, same posture
        // as ticket_payouts. Unlike ticketing, contributions have no
        // commission split, so there is no separate ledger table mirroring
        // ticket_revenue_entries: contribution_payments (status=completed)
        // already IS the append-only "money in" record, and this table is
        // the only "money out" record. A pending balance is computed live —
        // see ContributionRevenueAnalyticsService::balanceFor() — as
        // SUM(contribution_payments.amount where completed) minus
        // SUM(contribution_payouts.amount), both for the event.
        Schema::create('contribution_payouts', function (Blueprint $table) {
            $table->id();

            // nullOnDelete, not cascade: this is append-only financial
            // history and must survive a soft-deleted (or, later, force-
            // deleted) event, same rule ticket_payouts follows.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('ZMW');
            $table->date('paid_on');
            $table->string('note')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('admins')->nullOnDelete();

            $table->timestamps();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_payouts');
    }
};
