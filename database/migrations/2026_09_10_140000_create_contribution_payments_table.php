<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per installment attempt against an event_contributions
        // pledge — same shape as ticket_payments (a pledge can take several
        // of these over time; a ticket order only ever has one). See
        // plans/contributions.md.
        Schema::create('contribution_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_contribution_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('payment_method', 20);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('ZMW');
            $table->string('status', 20)->default('pending');
            $table->string('lenco_transaction_id')->nullable();
            $table->string('lenco_reference')->nullable();
            $table->string('lenco_status')->nullable();
            $table->json('lenco_response')->nullable();
            $table->string('payment_reference')->unique();
            $table->text('payment_instructions')->nullable();
            $table->json('bank_details')->nullable();
            $table->string('payment_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('webhook_received')->default(false);
            $table->json('webhook_payload')->nullable();
            $table->timestamp('webhook_received_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('lenco_transaction_id');
            $table->index('lenco_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_payments');
    }
};
