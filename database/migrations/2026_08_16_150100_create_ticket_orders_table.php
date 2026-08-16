<?php

use App\Enums\TicketOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('order_reference')->unique();
            $table->string('cart_id', 36);
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_phone')->nullable();
            $table->string('status', 20)->default(TicketOrderStatus::PendingPayment->value);
            $table->string('currency', 3)->default('ZMW');
            // Money columns — see plans/ticketing.md §5.4 for the absorb/pass-through math.
            $table->decimal('face_value', 12, 2);
            $table->decimal('commission_percent', 5, 2);
            $table->string('commission_mode', 20);
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('buyer_total', 12, 2);
            $table->decimal('host_amount', 12, 2);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_orders');
    }
};
