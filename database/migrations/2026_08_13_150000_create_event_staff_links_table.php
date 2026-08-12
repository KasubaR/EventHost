<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_staff_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            /** Bearer secret in the URL — same trust model as guests.invitation_token. */
            $table->string('token', 64)->unique();

            /** Host-facing label, e.g. "Front door", "Sarah's phone". */
            $table->string('label')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_staff_links');
    }
};
