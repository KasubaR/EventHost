<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copyright / takedown complaints about the background music on an invitation. The event and
     * the handling admin are nullOnDelete so a complaint outlives both; audio_path keeps what was
     * playing when the report came in, because removing the track clears it from the event.
     */
    public function up(): void
    {
        Schema::create('audio_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_name', 255)->nullable();
            $table->string('audio_path', 500)->nullable();
            $table->string('reporter_name', 100);
            $table->string('reporter_email', 150);
            $table->string('rights_holder', 150)->nullable();
            $table->text('details');
            $table->string('status', 16)->default('open')->index();
            $table->foreignId('handled_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_reports');
    }
};
