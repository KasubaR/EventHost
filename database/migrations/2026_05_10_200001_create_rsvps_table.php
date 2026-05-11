<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rsvps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32);

            $table->unsignedInteger('attendee_count')->default(1);

            $table->text('message')->nullable();

            $table->string('meal_preference')->nullable();

            $table->string('transportation_note')->nullable();

            $table->string('song_request')->nullable();

            $table->timestamps();

            $table->unique('guest_id');
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvps');
    }
};
