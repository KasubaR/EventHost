<?php

use App\Enums\ContributionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per contributor "pledge" toward an event's fixed
        // contribution amount. amount_paid is a running total of successful
        // installments only — see plans/contributions.md. target_amount is a
        // snapshot of events.contribution_amount at creation, so an admin
        // changing the amount later never rewrites a pledge already in
        // progress (same reasoning TicketOrder snapshots commission fields).
        Schema::create('event_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('contributor_name');
            $table->string('contributor_phone');
            $table->string('contributor_email')->nullable();
            $table->decimal('target_amount', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('currency', 3)->default('ZMW');
            $table->string('status', 20)->default(ContributionStatus::Pending->value);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'contributor_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_contributions');
    }
};
