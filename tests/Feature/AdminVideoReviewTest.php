<?php

namespace Tests\Feature;

use App\Enums\ReviewMediaType;
use App\Enums\ReviewStatus;
use App\Models\Admin;
use App\Models\Review;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminVideoReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'body' => 'We ran our whole wedding through Event Host and it just worked.',
            'author_name' => 'Namwali Musonda',
            'author_context' => 'Wedding · Lusaka',
            'rating' => 5,
            'video_ref' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_featured' => '1',
            'featured_sort_order' => 10,
        ], $overrides);
    }

    public function test_support_role_cannot_add_a_video_review(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('support');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.reviews.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_admin_can_add_a_video_review_from_a_pasted_youtube_url(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.reviews.store'), $this->payload())
            ->assertRedirect(route('admin.reviews.index'))
            ->assertSessionHas('status', 'review-created');

        $review = Review::query()->firstOrFail();

        $this->assertSame('youtube:dQw4w9WgXcQ', $review->video_ref);
        $this->assertSame(ReviewMediaType::Video, $review->media_type);
        $this->assertSame(Review::SOURCE_ADMIN, $review->source);
        // The admin is the moderator, so their own review needs no queue.
        $this->assertSame(ReviewStatus::Approved, $review->status);
        $this->assertNotNull($review->approved_at);
        $this->assertTrue($review->is_featured);
        $this->assertNull($review->user_id);
        $this->assertNull($review->event_id);
    }

    /**
     * Every URL shape InvitationVideoBackground understands should land on the
     * same stored reference.
     */
    public function test_short_and_embed_urls_normalize_to_the_same_reference(): void
    {
        $forms = [
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'dQw4w9WgXcQ',
        ];

        foreach ($forms as $index => $form) {
            $this->actingAs($this->superAdmin(), 'admin')
                ->post(route('admin.reviews.store'), $this->payload([
                    'video_ref' => $form,
                    'author_name' => 'Host '.$index,
                ]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(
            ['youtube:dQw4w9WgXcQ'],
            Review::query()->pluck('video_ref')->unique()->values()->all()
        );
    }

    public function test_a_link_that_is_not_a_youtube_video_is_rejected(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.reviews.store'), $this->payload([
                'video_ref' => 'https://vimeo.com/123456789',
            ]))
            ->assertSessionHasErrors('video_ref');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_poster_and_photo_uploads_are_converted_to_webp(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->post(route('admin.reviews.store'), $this->payload([
                'video_poster' => UploadedFile::fake()->image('poster.jpg', 1280, 720),
                'author_photo' => UploadedFile::fake()->image('face.jpg', 400, 400),
            ]))
            ->assertSessionHasNoErrors();

        $review = Review::query()->firstOrFail();

        $this->assertStringEndsWith('.webp', $review->video_poster);
        $this->assertStringEndsWith('.webp', $review->author_photo);
        Storage::disk('public')->assertExists($review->video_poster);
        Storage::disk('public')->assertExists($review->author_photo);
    }

    public function test_admin_can_replace_the_video_on_an_existing_review(): void
    {
        $review = Review::factory()->video()->approved()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->updatePayload($review, [
                'video_ref' => 'https://youtu.be/aaaaaaaaaaa',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('youtube:aaaaaaaaaaa', $review->refresh()->video_ref);
    }

    public function test_leaving_the_link_blank_keeps_the_current_video(): void
    {
        $review = Review::factory()->video()->approved()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->updatePayload($review, [
                'video_ref' => '',
                'body' => 'Corrected the wording without touching the video.',
            ]))
            ->assertSessionHasNoErrors();

        $review->refresh();

        $this->assertSame('youtube:dQw4w9WgXcQ', $review->video_ref);
        $this->assertSame('Corrected the wording without touching the video.', $review->body);
    }

    public function test_replacing_the_poster_drops_the_old_file(): void
    {
        Storage::disk('public')->put('reviews/old.webp', 'x');
        $review = Review::factory()->video()->approved()->create([
            'video_poster' => 'reviews/old.webp',
        ]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->updatePayload($review, [
                'video_poster' => UploadedFile::fake()->image('new.jpg', 1280, 720),
            ]))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('reviews/old.webp');
        Storage::disk('public')->assertExists($review->refresh()->video_poster);
    }

    public function test_removing_the_poster_leaves_the_review_featured(): void
    {
        Storage::disk('public')->put('reviews/live.webp', 'x');
        $review = Review::factory()->video()->featured(10)->create([
            'video_poster' => 'reviews/live.webp',
        ]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->delete(route('admin.reviews.poster.destroy', $review))
            ->assertRedirect(route('admin.reviews.index'))
            ->assertSessionHas('status', 'review-poster-removed');

        $review->refresh();

        $this->assertNull($review->video_poster);
        // The video is the requirement for featuring, not the poster.
        $this->assertTrue($review->is_featured);
        Storage::disk('public')->assertMissing('reviews/live.webp');
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(Review $review, array $overrides = []): array
    {
        return array_merge([
            'status' => ReviewStatus::Approved->value,
            'moderation_note' => null,
            'body' => $review->body,
            'rating' => $review->rating,
            'author_name' => $review->author_name,
            'author_context' => $review->author_context,
            'is_featured' => $review->is_featured ? '1' : '0',
            'featured_sort_order' => $review->featured_sort_order,
            'video_ref' => '',
        ], $overrides);
    }
}
