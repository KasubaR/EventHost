<?php

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use App\Models\User;
use App\Support\InvitationLayoutVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function template(string $name, SubscriptionTier $tier, array $attributes = []): InvitationTemplate
    {
        return InvitationTemplate::factory()->create($attributes + [
            'name' => $name,
            'min_subscription_tier' => $tier,
            // A countdown-capable layout cannot be Base; Standard can be any tier.
            'layout_variant' => InvitationLayoutVariant::STANDARD,
        ]);
    }

    /**
     * The page split at the Pro section: [before it, from it on].
     *
     * @return array{0: string, 1: string}
     */
    private function sections(string $html): array
    {
        $parts = explode('id="pro"', $html, 2);
        $this->assertCount(2, $parts, 'The Pro section is missing.');

        return [explode('id="base"', $parts[0], 2)[1] ?? '', $parts[1]];
    }

    public function test_a_guest_sees_templates_grouped_into_base_and_pro(): void
    {
        $this->template('Alder Base Layout', SubscriptionTier::Base);
        $this->template('Free Starter Layout', SubscriptionTier::None);
        $this->template('Birch Pro Layout', SubscriptionTier::Pro);
        $this->template('Cedar Pro Plus Layout', SubscriptionTier::ProPlus);

        $html = $this->get(route('templates.gallery'))->assertOk()->getContent();
        [$base, $pro] = $this->sections($html);

        $this->assertStringContainsString('Alder Base Layout', $base);
        $this->assertStringContainsString('Free Starter Layout', $base);
        $this->assertStringNotContainsString('Birch Pro Layout', $base);

        $this->assertStringContainsString('Birch Pro Layout', $pro);
        $this->assertStringContainsString('Cedar Pro Plus Layout', $pro);
        $this->assertStringContainsString('Pro+', $pro);
        $this->assertStringNotContainsString('Alder Base Layout', $pro);
    }

    public function test_inactive_templates_are_not_listed(): void
    {
        $this->template('Live Layout', SubscriptionTier::Base);
        $this->template('Retired Layout', SubscriptionTier::Base, ['is_active' => false]);

        $this->get(route('templates.gallery'))
            ->assertOk()
            ->assertSee('Live Layout')
            ->assertDontSee('Retired Layout');
    }

    public function test_a_category_narrows_both_groups_and_an_unknown_one_shows_everything(): void
    {
        $weddings = InvitationTemplateCategory::query()->firstOrCreate(['slug' => 'wedding'], ['name' => 'Wedding', 'sort_order' => 10]);
        $birthdays = InvitationTemplateCategory::query()->firstOrCreate(['slug' => 'birthday'], ['name' => 'Birthday', 'sort_order' => 20]);

        $this->template('Wedding Base Layout', SubscriptionTier::Base)->categories()->attach($weddings);
        $this->template('Wedding Pro Layout', SubscriptionTier::Pro)->categories()->attach($weddings);
        $this->template('Birthday Base Layout', SubscriptionTier::Base)->categories()->attach($birthdays);

        $this->get(route('templates.gallery', ['category' => 'wedding']))
            ->assertOk()
            ->assertSee('Wedding Base Layout')
            ->assertSee('Wedding Pro Layout')
            ->assertDontSee('Birthday Base Layout');

        $this->get(route('templates.gallery', ['category' => 'birthday']))
            ->assertOk()
            ->assertSee('Birthday Base Layout')
            ->assertSee('No Pro templates in Birthday yet.');

        $this->get(route('templates.gallery', ['category' => 'no-such-category']))
            ->assertOk()
            ->assertSee('Wedding Base Layout')
            ->assertSee('Birthday Base Layout');
    }

    public function test_a_guest_is_sent_to_sign_up_and_a_base_host_is_offered_the_upgrade(): void
    {
        $pro = $this->template('Birch Pro Layout', SubscriptionTier::Pro);
        $base = $this->template('Alder Base Layout', SubscriptionTier::Base);

        $this->get(route('templates.gallery'))
            ->assertSee(route('register'), false)
            ->assertSee(route('templates.preview', $pro), false)
            ->assertDontSee('Upgrade to Pro');

        $host = User::factory()->create(['status' => 'active', 'subscription_tier' => SubscriptionTier::Base]);

        $this->actingAs($host)
            ->get(route('templates.gallery'))
            ->assertOk()
            ->assertSee('Upgrade to Pro')
            ->assertSee(route('events.create', ['template' => $base->slug]), false);
    }

    public function test_the_site_header_links_to_the_gallery(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('templates.gallery').'" class=""', false);

        $this->get(route('templates.gallery'))
            ->assertOk()
            ->assertSee('href="'.route('templates.gallery').'" class="is-active"', false);
    }

    public function test_a_guest_previewing_a_template_goes_back_to_the_gallery(): void
    {
        $template = $this->template('Alder Base Layout', SubscriptionTier::Base);

        $this->get(route('templates.preview', $template))
            ->assertOk()
            ->assertSee('href="'.route('templates.gallery').'" class="tpl-preview-bar-back"', false);
    }
}
