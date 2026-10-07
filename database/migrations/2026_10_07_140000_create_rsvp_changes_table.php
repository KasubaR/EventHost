<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * plans/rsvp-status-changes.md Phase 4 — a history of how each guest's ANSWER changed (status or seats), written by
 * RsvpSubmissionService under the event lock: the previous answer, the new one, which channel it came through, and who
 * (a host, for an override). `rsvps` keeps only the current row, so without this nothing says what a guest said before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rsvp_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rsvp_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->unsignedSmallInteger('from_seats')->nullable();
            $table->string('to_status', 20);
            $table->unsignedSmallInteger('to_seats')->default(0);
            // web_token | web_open | group | whatsapp | api | host
            $table->string('channel', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['guest_id', 'id']);
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvp_changes');
    }
};
