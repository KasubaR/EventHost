<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-recorded disbursement (Phase 23) — manual, no Lenco call. The
        // authoritative accounting trail is the linked ticket_revenue_entries
        // row (type=payout, host_amount negative); this table is the
        // admin-facing record: who paid, how much, on what date, with what
        // note. Same append-only / nullOnDelete posture as
        // ticket_revenue_entries — deleting an event must not erase payout
        // history.
        Schema::create('ticket_payouts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_revenue_entry_id')->nullable()->constrained()->nullOnDelete();

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
        Schema::dropIfExists('ticket_payouts');
    }
};
