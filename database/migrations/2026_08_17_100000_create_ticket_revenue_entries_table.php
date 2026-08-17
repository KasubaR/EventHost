<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only host ledger, same shape as credit_transactions — see
        // App\Services\TicketRevenueLedgerService. Only "sale" is written
        // today; payout/adjustment are later phases, not stubbed here
        // (plans/ticketing.md's "don't build ahead of the phase that uses
        // it" rule). Buyer refunds are off-platform and never post here.
        Schema::create('ticket_revenue_entries', function (Blueprint $table) {
            $table->id();

            // nullOnDelete, not cascade: this is append-only financial history.
            // Deleting an event or order must not wipe sale rows (credit_transactions
            // uses the same rule for event_id). Both FKs are nullable so the row
            // can outlive the event/order it was attached to.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 20);

            // Copied verbatim from ticket_orders at the moment the order is
            // marked paid — never recalculated from today's ticket type price
            // or commission %. host_amount is signed (positive here; a future
            // payout row would store it negative) so balance_after can always
            // be SUM(host_amount) over prior rows without special-casing type.
            // buyer_fee / buyer_total are the extra the buyer paid and what
            // Lenco charged, so a deleted order still has the settlement
            // figures.
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('platform_fee', 12, 2);
            $table->decimal('buyer_fee', 12, 2)->default('0.00');
            $table->decimal('host_amount', 12, 2);
            $table->decimal('buyer_total', 12, 2)->default('0.00');
            $table->string('currency', 3)->default('ZMW');

            // Running balance for the event after this row, so history reads
            // without replaying the whole ledger — same reasoning as
            // credit_transactions.balance_after.
            $table->decimal('balance_after', 12, 2);

            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'created_at']);
            // One sale entry per order — the idempotency guard in
            // TicketRevenueLedgerService relies on this too, not just the
            // application-level check.
            $table->unique(['ticket_order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_revenue_entries');
    }
};
