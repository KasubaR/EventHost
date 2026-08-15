<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Signed: positive for a grant, negative for a spend.
            $table->integer('delta');
            $table->string('reason', 32);

            // What the movement was for. Both nullable — an admin grant has
            // neither, a purchase has only a payment.
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot of the balance after this row, so history reads without
            // replaying the whole ledger.
            $table->unsignedInteger('balance_after');
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
