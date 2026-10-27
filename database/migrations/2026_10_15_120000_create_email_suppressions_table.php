<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('reason', 32);
            $table->string('status_code', 16)->nullable();
            $table->string('diagnostic', 191)->nullable();
            $table->foreignId('notification_log_id')->nullable()->constrained('notification_logs')->nullOnDelete();
            $table->unsignedInteger('bounce_count')->default(1);
            $table->timestamp('first_bounced_at');
            $table->timestamp('last_bounced_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
