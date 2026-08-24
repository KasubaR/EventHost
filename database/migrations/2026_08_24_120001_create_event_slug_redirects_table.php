<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_slug_redirects');
    }
};
