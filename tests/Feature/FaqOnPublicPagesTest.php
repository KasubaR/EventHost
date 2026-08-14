<?php

namespace Tests\Feature;

use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqOnPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_homepage_faqs_render_and_unpublished_ones_do_not(): void
    {
        Faq::factory()->create(['question' => 'Published homepage question?']);
        Faq::factory()->unpublished()->create(['question' => 'Draft homepage question?']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Published homepage question?', escape: false)
            ->assertDontSee('Draft homepage question?', escape: false);
    }

    public function test_contact_faqs_render_on_the_contact_page_only(): void
    {
        Faq::factory()->contact()->create(['question' => 'A contact-only question?']);

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('A contact-only question?', escape: false);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('A contact-only question?', escape: false);
    }

    public function test_sort_order_controls_the_rendered_order(): void
    {
        Faq::factory()->create(['question' => 'Second question?', 'sort_order' => 20]);
        Faq::factory()->create(['question' => 'First question?', 'sort_order' => 10]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['First question?', 'Second question?'], escape: false);
    }

    public function test_the_faq_section_is_hidden_when_nothing_is_published(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Frequently asked questions', escape: false);

        $this->get(route('contact'))
            ->assertOk()
            ->assertDontSee('Quick answers', escape: false);
    }
}
