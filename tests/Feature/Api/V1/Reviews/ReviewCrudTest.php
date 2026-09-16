<?php

namespace Tests\Feature\Api\V1\Reviews;

use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice E — /api/v1/reviews. JSON sibling of App\Http\Controllers\ReviewController,
 * mirroring tests/Feature/ReviewSubmissionTest.php's fixture shape.
 */
class ReviewCrudTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function pastEventFor(User $user): Event
    {
        return Event::factory()->published()->create([
            'user_id' => $user->id,
            'event_date' => now()->subWeek()->format('Y-m-d'),
        ]);
    }

    public function test_host_can_review_a_past_published_event(): void
    {
        $user = User::factory()->create();
        $event = $this->pastEventFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson(route('api.v1.reviews.store'), [
                'event_id' => $event->id,
                'rating' => 5,
                'body' => 'The invitations went out in minutes and every RSVP landed in one place.',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('review.status.value', 'pending');

        $review = Review::query()->firstOrFail();
        $this->assertSame($user->id, $review->user_id);
        $this->assertSame(Review::SOURCE_USER, $review->source);
    }

    /**
     * The whole point of this resource: the app must be able to see the
     * consequence of editing an approved+featured review without knowing the
     * rule itself.
     */
    public function test_editing_an_approved_featured_review_resets_it_to_pending(): void
    {
        $user = User::factory()->create();
        $event = $this->pastEventFor($user);
        $review = Review::factory()->for($user)->for($event)->create([
            'status' => ReviewStatus::Approved,
            'is_featured' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson(route('api.v1.reviews.update', $review), [
                'rating' => 4,
                'body' => 'Updated body text that still clears the minimum length rule easily.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('review.status.value', 'pending');
        $response->assertJsonPath('review.is_featured', false);
    }

    public function test_a_stranger_cannot_edit_someone_elses_review(): void
    {
        $owner = User::factory()->create();
        $event = $this->pastEventFor($owner);
        $review = Review::factory()->for($owner)->for($event)->create();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->patchJson(route('api.v1.reviews.update', $review), [
                'rating' => 3,
                'body' => 'Trying to edit a review that is not mine at all.',
            ])
            ->assertForbidden();
    }

    public function test_destroy_removes_the_review(): void
    {
        $user = User::factory()->create();
        $event = $this->pastEventFor($user);
        $review = Review::factory()->for($user)->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson(route('api.v1.reviews.destroy', $review))
            ->assertOk();

        $this->assertNull(Review::query()->find($review->id));
    }
}
