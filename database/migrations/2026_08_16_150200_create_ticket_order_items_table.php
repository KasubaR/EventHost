<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_order_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, not cascade: a historical order line must survive a
            // ticket type being deleted later — name/price are already snapshotted.
            $table->foreignId('ticket_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_type_name');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            $table->index('ticket_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_order_items');
    }
};
