<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's request for our team's help — the consent gate for admins acting as that
     * client (plans/admin-create-events.md, Step 0).
     */
    public function up(): void
    {
        Schema::create('admin_help_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->text('message');
            $table->string('contact_preference')->nullable();
            $table->string('status', 32)->default('open');
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('decline_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_help_requests');
    }
};
