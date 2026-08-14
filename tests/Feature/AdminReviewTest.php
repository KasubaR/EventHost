<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\Admin;
use App\Models\Review;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function supportAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('support');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Review $review, array $overrides = []): array
    {
        return array_merge([
            'status' => ReviewStatus::Approved->value,
            'moderation_note' => null,
            'body' => $review->body,
            'rating' => $review->rating,
            'author_name' => $review->author_name,
            'author_context' => $review->author_context,
            'is_featured' => '0',
            'featured_sort_order' => 0,
        ], $overrides);
    }

    public function test_support_role_cannot_manage_reviews(): void
    {
        $this->actingAs($this->supportAdmin(), 'admin')
            ->get(route('admin.reviews.index'))
            ->assertForbidden();
    }

    public function test_super_admin_sees_the_moderation_queue(): void
    {
        $review = Review::factory()->create(['body' => 'Everything ran on rails from start to finish.']);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.reviews.index'))
            ->assertOk()
            ->assertSee($review->author_name, escape: false)
            ->assertSee('Everything ran on rails from start to finish.', escape: false);
    }

    public function test_admin_can_approve_and_feature_a_review(): void
    {
        $review = Review::factory()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->payload($review, [
                'is_featured' => '1',
                'featured_sort_order' => 20,
            ]))
            ->assertRedirect(route('admin.reviews.index'))
            ->assertSessionHas('status', 'review-updated');

        $review->refresh();

        $this->assertSame(ReviewStatus::Approved, $review->status);
        $this->assertTrue($review->is_featured);
        $this->assertSame(20, $review->featured_sort_order);
        $this->assertNotNull($review->approved_at);
    }

    public function test_admin_can_correct_the_attribution(): void
    {
        $review = Review::factory()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->payload($review, [
                'author_name' => 'Mutinta Phiri',
                'author_context' => 'Graduation · Livingstone',
                'rating' => 4,
            ]))
            ->assertSessionHasNoErrors();

        $review->refresh();

        $this->assertSame('Mutinta Phiri', $review->author_name);
        $this->assertSame('Graduation · Livingstone', $review->author_context);
        $this->assertSame(4, $review->rating);
    }

    public function test_rejecting_a_review_requires_a_note_for_the_host(): void
    {
        $review = Review::factory()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->payload($review, [
                'status' => ReviewStatus::Rejected->value,
                'moderation_note' => null,
            ]))
            ->assertSessionHasErrors('moderation_note');

        $this->assertSame(ReviewStatus::Pending, $review->refresh()->status);
    }

    public function test_unpublishing_a_featured_review_also_takes_it_off_the_homepage(): void
    {
        $review = Review::factory()->featured(10)->create();

        // The "feature" box is still ticked in the submitted form — the status
        // change is what decides, so it is coerced rather than rejected.
        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->payload($review, [
                'status' => ReviewStatus::Rejected->value,
                'moderation_note' => 'Duplicate of another review.',
                'is_featured' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $review->refresh();

        $this->assertSame(ReviewStatus::Rejected, $review->status);
        $this->assertFalse($review->is_featured);
        $this->assertNull($review->approved_at);
        $this->assertSame('Duplicate of another review.', $review->moderation_note);
    }

    public function test_a_video_review_cannot_be_featured_without_a_video(): void
    {
        $review = Review::factory()->video(null)->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->patch(route('admin.reviews.update', $review), $this->payload($review, [
                'is_featured' => '1',
            ]))
            ->assertSessionHasErrors('is_featured');

        $this->assertFalse($review->refresh()->is_featured);
    }

    public function test_admin_can_delete_a_review(): void
    {
        $review = Review::factory()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->delete(route('admin.reviews.destroy', $review))
            ->assertRedirect(route('admin.reviews.index'))
            ->assertSessionHas('status', 'review-deleted');

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }
}
