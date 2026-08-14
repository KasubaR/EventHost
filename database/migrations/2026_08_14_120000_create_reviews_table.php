<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            // Null for admin-authored reviews. Cascade so a deleted account takes
            // its reviews with it.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('source', 10)->default('user');
            $table->string('media_type', 10)->default('text');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('body');

            // Denormalized at submit time: the homepage then renders without
            // joining users/events, and a host renaming their profile later does
            // not silently rewrite a published testimonial.
            $table->string('author_name');
            $table->string('author_context')->nullable();
            $table->string('author_photo')->nullable();

            // Phase 2 (admin-authored video reviews). Columns land now so that
            // phase adds no schema change.
            $table->string('video_ref')->nullable();
            $table->string('video_poster')->nullable();

            $table->string('status', 10)->default('pending');
            $table->text('moderation_note')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('featured_sort_order')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_featured', 'featured_sort_order']);

            // One review per hosted event. MySQL treats NULLs as distinct in a
            // unique index, so every admin-authored row (both columns null)
            // coexists happily under this constraint — it only binds real user
            // submissions, which is the intent.
            $table->unique(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
