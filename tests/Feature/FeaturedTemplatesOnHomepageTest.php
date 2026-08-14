<?php

namespace Tests\Feature;

use App\Models\InvitationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeaturedTemplatesOnHomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_is_hidden_when_nothing_is_featured(): void
    {
        // The seeded templates are all unfeatured by default.
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Invitation Templates', escape: false);
    }

    public function test_featured_templates_render_in_order_with_a_preview_link(): void
    {
        $second = InvitationTemplate::factory()->featured(20)->create(['name' => 'Zephyr Second']);
        $first = InvitationTemplate::factory()->featured(10)->create(['name' => 'Alder First']);

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('Invitation Templates', escape: false)
            ->assertSee('Alder First', escape: false)
            ->assertSee('Zephyr Second', escape: false)
            ->assertSee(route('templates.preview', $first), escape: false)
            ->assertSee(route('templates.preview', $second), escape: false)
            ->assertSeeInOrder(['Alder First', 'Zephyr Second'], escape: false);
    }

    public function test_featured_templates_without_an_image_or_inactive_are_skipped(): void
    {
        InvitationTemplate::factory()->featured(10)->create(['name' => 'Shown Template']);
        InvitationTemplate::factory()->featured(20)->create([
            'name' => 'Imageless Template',
            'preview_image' => null,
        ]);
        InvitationTemplate::factory()->featured(30)->create([
            'name' => 'Retired Template',
            'is_active' => false,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Shown Template', escape: false)
            ->assertDontSee('Imageless Template', escape: false)
            ->assertDontSee('Retired Template', escape: false);
    }

    public function test_homepage_shows_at_most_four_featured_templates(): void
    {
        foreach (range(1, 6) as $i) {
            InvitationTemplate::factory()->featured($i * 10)->create(['name' => 'Featured Number '.$i]);
        }

        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('Featured Number 4', escape: false)
            ->assertDontSee('Featured Number 5', escape: false)
            ->assertDontSee('Featured Number 6', escape: false);
    }

    public function test_guest_can_open_a_template_preview(): void
    {
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $this->get(route('templates.preview', $tpl))
            ->assertOk()
            ->assertSee('Template preview', escape: false)
            ->assertSee(route('register'), escape: false);
    }
}
