<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan_key', 24);
            $table->unsignedTinyInteger('credits_granted')->default(1);
            $table->string('user_ref', 40)->index();

            $table->string('payment_method', 20);
            $table->string('provider', 20)->nullable();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('ZMW');

            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded'])
                ->default('pending');

            $table->string('lenco_transaction_id', 100)->nullable()->index();
            $table->string('lenco_reference', 100)->nullable()->index();
            $table->string('lenco_status', 50)->nullable();
            $table->json('lenco_response')->nullable();

            $table->string('payment_reference', 120)->nullable()->unique();

            $table->text('payment_instructions')->nullable();
            $table->json('bank_details')->nullable();
            $table->string('payment_url', 500)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->boolean('webhook_received')->default(false);
            $table->json('webhook_payload')->nullable();
            $table->timestamp('webhook_received_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
