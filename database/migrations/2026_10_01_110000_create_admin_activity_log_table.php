<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for admins acting as a client (plans/admin-create-events.md, Step 3).
     * A dedicated table rather than the spatie activity log: the questions asked of it are
     * "what did this admin do to this client / this event", which are plain indexed columns
     * here instead of JSON properties. Every foreign key is nullOnDelete so the trail
     * outlives a deleted admin, client or event.
     */
    public function up(): void
    {
        Schema::create('admin_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('help_request_id')->nullable()->constrained('admin_help_requests')->nullOnDelete();
            $table->string('action', 64);
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['event_id', 'created_at']);
            $table->index(['admin_id', 'created_at']);
        });

        // Read-only provenance: which admin created the event while acting as its owner.
        // Nothing gates on it.
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('created_by_admin_id')->nullable()->after('user_id')
                ->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_admin_id');
        });

        Schema::dropIfExists('admin_activity_log');
    }
};
