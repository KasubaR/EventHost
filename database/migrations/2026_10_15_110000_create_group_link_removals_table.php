<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What is left of a group's shared RSVP link after the group is deleted or the link turned off (plans/guest-groups-gaps.md Phase 2),
     * so the old address can say "this link was closed" instead of "not found". Only a SHA-256 of the token is kept, never the token,
     * and no names: a stranger holding the old link learns nothing the page does not already tell them. Goes with the event.
     */
    public function up(): void
    {
        Schema::create('group_link_removals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('reason', 20);
            $table->timestamp('removed_at')->useCurrent();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_link_removals');
    }
};
