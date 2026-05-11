<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            $table->string('email')->nullable();

            $table->string('phone')->nullable();

            $table->string('invitation_token')->nullable()->unique();

            $table->boolean('plus_one_allowed')->default(false);

            /** Tracks which reminder buckets were sent: e.g. ["7","3","1"]. */
            $table->json('rsvp_reminders_sent')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'email']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
