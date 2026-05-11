<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            $table->enum('event_type', [
                'wedding',
                'birthday',
                'graduation',
                'corporate',
                'baby_shower',
                'funeral',
                'church',
            ]);

            $table->text('description')->nullable();

            $table->date('event_date');
            $table->time('event_time');

            $table->string('venue')->nullable();

            $table->string('location_name')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('cover_image')->nullable();

            $table->boolean('is_public')->default(true);

            $table->timestamp('rsvp_deadline')->nullable();

            $table->unsignedInteger('guest_limit')->nullable();

            $table->boolean('allow_plus_one')->default(false);

            $table->boolean('show_guest_list')->default(false);

            $table->string('slug')->unique();

            $table->boolean('is_published')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'is_published']);
            $table->index('event_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
