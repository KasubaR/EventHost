<?php

use App\Enums\TicketReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
            // Anonymous buyer's cart identifier (uuid, kept in their session) — there is
            // no user account to key a hold off. See plans/ticketing.md §5.1.
            $table->string('cart_id', 36);
            $table->unsignedInteger('quantity');
            // Locked in at hold time so a host editing the price mid-hold can't
            // change what the buyer is ultimately charged.
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->string('status', 20)->default(TicketReservationStatus::Held->value);
            $table->timestamp('expires_at');
            $table->foreignId('ticket_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // Hot path: capacity math on every hold attempt and every public
            // ticket-type listing.
            $table->index(['ticket_type_id', 'status', 'expires_at']);
            $table->index(['cart_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reservations');
    }
};
