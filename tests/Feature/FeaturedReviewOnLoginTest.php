<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturedReviewOnLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_is_hidden_when_nothing_is_featured(): void
    {
        Review::factory()->approved()->create([
            'author_name' => 'Approved Host',
            'body' => 'The dashboard is incredible and I would say so again.',
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Approved Host', escape: false)
            ->assertDontSee('The dashboard is incredible', escape: false)
            ->assertDontSee('auth-testi', escape: false);
    }

    public function test_the_first_featured_review_renders_as_the_login_quote(): void
    {
        Review::factory()->featured(20)->create([
            'author_name' => 'Zephyr Second',
            'author_context' => 'Wedding · Kitwe',
            'body' => 'Second in line should stay off the login sidebar.',
        ]);
        Review::factory()->featured(10)->create([
            'author_name' => 'Alder First',
            'author_context' => 'Corporate Event · Ndola',
            'body' => 'Live responses and reminders saved the whole week.',
            'rating' => 4,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Alder First', escape: false)
            ->assertSee('Corporate Event · Ndola', escape: false)
            ->assertSee('Live responses and reminders saved the whole week.', escape: false)
            ->assertSee('4 out of 5 stars', escape: false)
            ->assertDontSee('Zephyr Second', escape: false)
            ->assertDontSee('Second in line should stay off the login sidebar.', escape: false);
    }

    public function test_unapproved_and_unfeatured_reviews_are_never_shown(): void
    {
        Review::factory()->featured(10)->create(['author_name' => 'Shown Host']);
        Review::factory()->approved()->create(['author_name' => 'Approved Host']);
        Review::factory()->create(['author_name' => 'Pending Host', 'is_featured' => true]);
        Review::factory()->rejected()->create(['author_name' => 'Rejected Host', 'is_featured' => true]);

        $response = $this->get(route('login'));

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

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Written Host', escape: false)
            ->assertDontSee('Videoless Host', escape: false);
    }
}
