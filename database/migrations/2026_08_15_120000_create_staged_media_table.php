<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files uploaded from the event edit page the moment they are picked, before the
 * form that will reference them is saved.
 *
 * A row is a claim ticket: the binary is already on the 'public' disk in its final
 * directory, and the save consumes the row to learn the path. Scoping every lookup
 * to (event_id, user_id) is what stops one user handing another user's path to a
 * controller that would then delete or publish it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staged_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 'gallery' | 'hero_portrait' | 'couple' | 'speaker:0'..'speaker:3' | 'cover' | 'audio'
            $table->string('slot', 32);
            $table->string('path', 512);
            $table->string('original_name', 255)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'user_id', 'slot']);
            // The pruner sweeps by age across every event.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staged_media');
    }
};
