<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            /** Nullable so removing a table doesn't delete photos already posted to it. */
            $table->foreignId('event_table_id')->nullable()->constrained('event_tables')->nullOnDelete();

            $table->string('path');
            $table->string('thumbnail_path');

            /** Free-text, guest-supplied, never verified. */
            $table->string('uploader_name')->nullable();

            $table->enum('status', ['pending', 'approved', 'hidden'])->default('approved');

            /** hash('sha256', ip . salt) — abuse tracking without storing a raw IP. */
            $table->string('ip_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index('event_table_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_photos');
    }
};
