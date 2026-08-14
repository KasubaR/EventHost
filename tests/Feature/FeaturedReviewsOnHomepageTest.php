<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturedReviewsOnHomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_is_hidden_when_nothing_is_featured(): void
    {
        Review::factory()->approved()->create();

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Loved by hosts everywhere', escape: false);
    }

    public function test_featured_reviews_render_in_admin_defined_order(): void
    {
        Review::factory()->featured(20)->create(['author_name' => 'Zephyr Second']);
        Review::factory()->featured(10)->create(['author_name' => 'Alder First']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Loved by hosts everywhere', escape: false)
            ->assertSeeInOrder(['Alder First', 'Zephyr Second'], escape: false);
    }

    public function test_unapproved_and_unfeatured_reviews_are_never_shown(): void
    {
        Review::factory()->featured(10)->create(['author_name' => 'Shown Host']);
        Review::factory()->approved()->create(['author_name' => 'Approved Host']);
        Review::factory()->create(['author_name' => 'Pending Host', 'is_featured' => true]);
        Review::factory()->rejected()->create(['author_name' => 'Rejected Host', 'is_featured' => true]);

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('Shown Host', escape: false)
            ->assertDontSee('Approved Host', escape: false)
            ->assertDontSee('Pending Host', escape: false)
            ->assertDontSee('Rejected Host', escape: false);
    }

    public function test_a_featured_video_review_without_a_video_is_skipped(): void
    {
        Review::factory()->video(null)->featured(10)->create(['author_name' => 'Videoless Host']);
        Review::factory()->featured(20)->create(['author_name' => 'Written Host']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Written Host', escape: false)
            ->assertDontSee('Videoless Host', escape: false);
    }

    public function test_homepage_shows_at_most_six_featured_reviews(): void
    {
        foreach (range(1, 8) as $i) {
            Review::factory()->featured($i * 10)->create(['author_name' => 'Featured Host '.$i]);
        }

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('Featured Host 6', escape: false)
            ->assertDontSee('Featured Host 7', escape: false)
            ->assertDontSee('Featured Host 8', escape: false);
    }

    public function test_the_rating_renders_as_stars(): void
    {
        Review::factory()->featured(10)->create([
            'author_name' => 'Four Star Host',
            'rating' => 4,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('4 out of 5 stars', escape: false);
    }
}
