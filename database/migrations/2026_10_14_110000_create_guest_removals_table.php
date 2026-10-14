<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tombstone for a guest the host deleted (plans/guest-deletion-gaps.md Phase 3). The guest row and its RSVP history
     * cascade away; this keeps just enough to say "removed" instead of "never existed": who, what they had answered, when
     * and by whom. The personal link is stored as a hash only, so a tombstone can recognise an old link but never be one.
     * It goes with the event (cascade), and the deleting user's account may go without taking it (nullOnDelete).
     */
    public function up(): void
    {
        Schema::create('guest_removals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone_key', 32)->nullable();
            $table->string('last_status', 32)->nullable();
            $table->unsignedSmallInteger('seats_freed')->default(0);
            $table->boolean('invitation_sent')->default(false);
            $table->timestamp('removed_at')->useCurrent();

            $table->index(['event_id', 'removed_at']);
            $table->index(['event_id', 'email']);
            $table->index(['event_id', 'phone_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_removals');
    }
};
