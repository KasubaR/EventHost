<?php

namespace Tests\Feature;

use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function pastEventFor(User $user): Event
    {
        return Event::factory()->published()->create([
            'user_id' => $user->id,
            'event_date' => now()->subWeek()->format('Y-m-d'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'event_id' => $event->id,
            'rating' => 5,
            'body' => 'The invitations went out in minutes and every RSVP landed in one place.',
        ], $overrides);
    }

    public function test_guest_is_redirected_from_the_reviews_page(): void
    {
        $this->get(route('reviews.index'))->assertRedirect('/login');
    }

    public function test_host_can_review_a_past_published_event(): void
    {
        $user = User::factory()->create(['name' => 'Chanda Bwalya']);
        $event = $this->pastEventFor($user);
        $event->update(['event_type' => 'wedding', 'location_name' => 'Lusaka']);

        $this->actingAs($user)
            ->post(route('reviews.store'), $this->payload($event))
            ->assertRedirect(route('reviews.index'))
            ->assertSessionHas('status', 'review-submitted');

        $review = Review::query()->firstOrFail();

        $this->assertSame($user->id, $review->user_id);
        $this->assertSame($event->id, $review->event_id);
        $this->assertSame(Review::SOURCE_USER, $review->source);
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertFalse($review->is_featured);
        // Attribution is snapshotted at submit time.
        $this->assertSame('Chanda Bwalya', $review->author_name);
        $this->assertSame('Wedding · Lusaka', $review->author_context);
    }

    public function test_an_event_that_has_not_happened_yet_cannot_be_reviewed(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->published()->create([
            'user_id' => $user->id,
            'event_date' => now()->addWeek()->format('Y-m-d'),
        ]);

        $this->actingAs($user)
            ->post(route('reviews.store'), $this->payload($event))
            ->assertSessionHasErrors('event_id');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_an_unpublished_event_cannot_be_reviewed(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'user_id' => $user->id,
            'event_date' => now()->subWeek()->format('Y-m-d'),
            'is_published' => false,
        ]);

        $this->actingAs($user)
            ->post(route('reviews.store'), $this->payload($event))
            ->assertSessionHasErrors('event_id');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_an_event_can_only_be_reviewed_once(): void
    {
        $user = User::factory()->create();
        $event = $this->pastEventFor($user);

        Review::factory()->create(['user_id' => $user->id, 'event_id' => $event->id]);

        $this->actingAs($user)
            ->post(route('reviews.store'), $this->payload($event))
            ->assertSessionHasErrors('event_id');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_a_host_cannot_review_someone_elses_event(): void
    {
        $user = User::factory()->create();
        $event = $this->pastEventFor(User::factory()->create());

        $this->actingAs($user)
            ->post(route('reviews.store'), $this->payload($event))
            ->assertSessionHasErrors('event_id');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_very_short_review_is_rejected(): void
    {
        $user = User::factory()->create();
        $event = $this->pastEventFor($user);

        $this->actingAs($user)
            ->post(route('reviews.store'), $this->payload($event, ['body' => 'Great!']))
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_editing_an_approved_review_sends_it_back_for_moderation(): void
    {
        $user = User::factory()->create();
        $event = $this->pastEventFor($user);
        $review = Review::factory()->featured(10)->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        $this->actingAs($user)
            ->patch(route('reviews.update', $review), [
                'rating' => 4,
                'body' => 'Updated: the check-in scanner saved us at the door on the day.',
            ])
            ->assertRedirect(route('reviews.index'))
            ->assertSessionHas('status', 'review-updated');

        $review->refresh();

        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertFalse($review->is_featured);
        $this->assertNull($review->approved_at);
        $this->assertSame(4, $review->rating);
    }

    public function test_a_host_cannot_edit_another_hosts_review(): void
    {
        $review = Review::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('reviews.update', $review), [
                'rating' => 1,
                'body' => 'Trying to overwrite a review that is not mine at all.',
            ])
            ->assertForbidden();
    }

    public function test_a_host_can_delete_their_own_review(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->pastEventFor($user)->id,
        ]);

        $this->actingAs($user)
            ->delete(route('reviews.destroy', $review))
            ->assertRedirect(route('reviews.index'))
            ->assertSessionHas('status', 'review-deleted');

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_a_host_cannot_delete_an_admin_authored_review(): void
    {
        $review = Review::factory()->video()->approved()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('reviews.destroy', $review))
            ->assertForbidden();

        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }

    public function test_the_page_lists_past_events_with_their_review_status(): void
    {
        $user = User::factory()->create();
        $reviewed = $this->pastEventFor($user);
        $reviewed->update(['name' => 'Reviewed Gathering']);
        Review::factory()->rejected('Please add a little more detail.')->create([
            'user_id' => $user->id,
            'event_id' => $reviewed->id,
        ]);

        $awaiting = $this->pastEventFor($user);
        $awaiting->update(['name' => 'Unreviewed Gathering']);

        $this->actingAs($user)
            ->get(route('reviews.index'))
            ->assertOk()
            ->assertSee('Reviewed Gathering', escape: false)
            ->assertSee('Unreviewed Gathering', escape: false)
            ->assertSee('Please add a little more detail.', escape: false);
    }
}
