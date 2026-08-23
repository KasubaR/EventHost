<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_staff', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Null until the invite is accepted (or immediately, for an invite
            // to an address that already has an account — see EventStaff::forEmail()).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('role', 20);

            /** Snapshot of the invited address — kept after acceptance too, so a
             *  pending row (no user_id yet) still has something to display/email. */
            $table->string('email');

            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();

            /** Bearer secret in the accept URL — same trust model as
             *  event_staff_links.token and guests.invitation_token. */
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();

            /** Null = still a pending invite. */
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'email']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_staff');
    }
};
