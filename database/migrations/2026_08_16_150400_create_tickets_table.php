<?php

use App\Enums\TicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_order_item_id')->constrained()->cascadeOnDelete();
            // Denormalized, same reasoning as ticket_types.event_id — scanner and
            // reporting queries in later phases must not join through orders.
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->nullable()->constrained()->nullOnDelete();
            // Secret ticket URL, same shape as Guest::invitation_token.
            $table->string('public_token', 48)->unique();
            $table->string('attendee_name')->nullable();
            $table->string('attendee_email')->nullable();
            $table->string('attendee_phone')->nullable();
            $table->decimal('price_paid', 12, 2);
            $table->string('status', 20)->default(TicketStatus::Valid->value);
            $table->timestamp('issued_at');
            $table->timestamps();

            // No check-in columns yet — that's Phase 3's migration, per
            // plans/ticketing.md's "do not migrate until the phase that uses it".
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
