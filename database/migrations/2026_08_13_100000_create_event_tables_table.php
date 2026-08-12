<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_tables', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            /** Short random slug used in the public table-QR URL, e.g. "e/{slug}/table/{code}". */
            $table->string('code', 16)->unique();

            $table->unsignedInteger('sort_order')->default(0);

            $table->unsignedInteger('photos_count')->default(0);

            $table->timestamps();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tables');
    }
};
