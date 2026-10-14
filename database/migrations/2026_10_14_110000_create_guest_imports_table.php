<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest import too big to run while the host waits (events.guest_import.sync_max_rows). The uploaded file sits on
     * the private `local` disk until the ImportEventGuests job finishes with it; this row keeps the result. Small imports
     * never get a row.
     */
    public function up(): void
    {
        Schema::create('guest_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->string('path')->nullable();
            $table->string('original_name');
            $table->string('reader_type', 10);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('capped_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->json('problems')->nullable();
            $table->json('skipped_rows')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_imports');
    }
};
