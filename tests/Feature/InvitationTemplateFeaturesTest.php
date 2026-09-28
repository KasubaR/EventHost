<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationPalettes;
use App\Support\WeddingInvitationView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationTemplateFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_template_library(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('templates.index'));

        $response->assertOk();
        $response->assertSee('Templates', escape: false);
        $response->assertSee('Browse thumbnails', escape: false);
    }

    public function test_authenticated_user_can_preview_template(): void
    {
        $user = User::factory()->create();
        // Pinned to a template that uses the unmodified sample event — previewSampleEvent()
        // renames the couple for five layout-specific slugs, so relying on whichever
        // template happens to be first would break whenever the seeder is reordered.
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('Template preview', escape: false);
        $response->assertSee('Celebration', escape: false);
    }

    public function test_preview_modern_minimal_renders_clean_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'modern-minimal')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-modern-minimal', escape: false);
        $response->assertSee('mm-hero', escape: false);
        $response->assertSee('mm-hero-photo', escape: false);
        $response->assertSee('Kasuba', escape: false);
        $response->assertSee('The Countdown', escape: false);
        $response->assertSee('data-inv-countdown', escape: false);
        $response->assertSee('mm-couple-grid', escape: false);
        $response->assertSee('mm-details-section', escape: false);
        $response->assertSee('mm-timeline', escape: false);
        $response->assertSee('Cocktail hour', escape: false);
        $response->assertSee('mm-gallery--even', escape: false);
    }

    /**
     * The Pro wedding standard: every template on a Pro wedding layout renders the same
     * sections (in any order, hero first) and asks for the same photos (1 cover, 3 couple
     * portraits, up to 6 gallery), and is tagged Wedding only. A new Pro wedding layout that
     * drifts from this fails here. (Pro Magazine is also tagged Wedding, on request, but is not
     * on the standard.)
     */
    public function test_every_pro_wedding_template_follows_the_wedding_standard(): void
    {
        $proWeddings = InvitationTemplate::query()
            ->where('is_active', true)
            ->whereIn('layout_variant', InvitationLayoutVariant::proWeddingLayouts())
            ->with('categories')
            ->get();

        $this->assertEqualsCanonicalizing(
            ['wedding-invitation', 'wedding-invitation-2', 'modern-minimal', 'wedding-midnight-gold', 'wedding-dusty-blue'],
            $proWeddings->pluck('slug')->all()
        );

        $expectedSections = ['hero', 'countdown', 'description', 'story', 'details', 'schedule', 'gallery', 'rsvp'];

        foreach ($proWeddings as $template) {
            $variant = $template->layout_variant;
            $sections = collect($template->default_sections)->pluck('type')->all();

            $this->assertSame(['wedding'], $template->categories->pluck('slug')->all(), $template->slug);
            $this->assertEqualsCanonicalizing($expectedSections, $sections, $template->slug);
            $this->assertSame('hero', $sections[0], $template->slug);
            $this->assertTrue(InvitationLayoutVariant::isProWedding($variant), $template->slug);
            $this->assertSame([], InvitationLayoutVariant::blockedSections($variant), $template->slug);
            $this->assertTrue(InvitationLayoutVariant::usesCoverImage($variant), $template->slug);
            $this->assertSame(3, InvitationLayoutVariant::maxCouplePhotoSlots($variant), $template->slug);
            $this->assertSame(6, InvitationLayoutVariant::maxGalleryImages($variant), $template->slug);
        }
    }

    public function test_a_section_missing_from_a_saved_order_is_inserted_after_its_template_neighbour(): void
    {
        $template = InvitationTemplate::query()->where('slug', 'wedding-invitation')->firstOrFail();

        // A design saved before the countdown section existed.
        $saved = collect(['hero', 'description', 'story', 'details', 'schedule', 'gallery', 'rsvp'])
            ->map(fn (string $type) => ['type' => $type, 'visible' => true])
            ->all();

        $merged = app(InvitationCustomizationService::class)->mergeSectionsForPersistence($template, $saved);

        $this->assertSame(
            ['hero', 'countdown', 'description', 'story', 'details', 'schedule', 'gallery', 'rsvp'],
            array_column($merged, 'type')
        );
    }

    public function test_every_template_has_exactly_one_category(): void
    {
        $expected = [
            'slate-minimal' => 'wedding',
            'base-wedding' => 'wedding',
            'event-invite' => 'graduation',
            'wedding-invitation' => 'wedding',
            'wedding-invitation-2' => 'wedding',
            'modern-minimal' => 'wedding',
            'wedding-midnight-gold' => 'wedding',
            'wedding-dusty-blue' => 'wedding',
            'pro-magazine' => 'wedding',
            'graduation-template-2-botanical-blush' => 'graduation',
            'beauty-for-ashes' => 'church',
        ];

        $actual = InvitationTemplate::query()->with('categories')->get()
            ->mapWithKeys(fn (InvitationTemplate $t) => [$t->slug => $t->categories->pluck('slug')->all()])
            ->all();

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual));
        foreach ($expected as $slug => $category) {
            $this->assertSame([$category], $actual[$slug], $slug);
        }
    }

    public function test_preview_wedding_invitation_noir_renders_dark_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation-2')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-wedding-invitation-noir', escape: false);
        $response->assertSee('wi2-hero', escape: false);
        $response->assertSee('Peter', escape: false);
        $response->assertSee('wi2-timeline', escape: false);
        $response->assertSee('wi2-couple-grid', escape: false);
        $response->assertSee('wi2-details-section', escape: false);
        $response->assertSee('wi2-countdown-section', escape: false);
        $response->assertSee('data-inv-countdown', escape: false);
        $response->assertSee('The Ridgecrest Estate Chapel', escape: false);
    }

    public function test_preview_midnight_gold_renders_its_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-midnight-gold')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-wedding-midnight-gold', escape: false);
        $response->assertSee('events-invitation-layout-wedding-midnight-gold.css', escape: false);
        $response->assertSee('Dancing+Script', escape: false);
        $response->assertSee('mg-hero-frame', escape: false);
        $response->assertSee('Mutale', escape: false);
        $response->assertSee('Chilufya', escape: false);
        $response->assertSee('data-inv-countdown', escape: false);
        $response->assertSee('Love Story', escape: false);
        $this->assertSame(3, substr_count($response->getContent(), 'class="mg-story-row"'));
        $response->assertSee('Kabulonga Garden Chapel', escape: false);
        $response->assertSee('Dress Code', escape: false);
        $response->assertSee('mg-timeline', escape: false);
        $response->assertSee('mg-gallery--count-6', escape: false);
        $response->assertSee('data-rsvp-preview', escape: false);
        $response->assertSee('mg-footer', escape: false);
    }

    public function test_preview_dusty_blue_renders_its_markup_in_its_own_section_order(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-dusty-blue')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-wedding-dusty-blue', escape: false);
        $response->assertSee('events-invitation-layout-wedding-dusty-blue.css', escape: false);
        $response->assertSee('Mrs+Saint+Delafield', escape: false);
        $response->assertSee('db-strip', escape: false);
        $this->assertSame(3, substr_count($response->getContent(), 'class="db-strip-photo"'));
        $response->assertSee('Bwalya', escape: false);
        $response->assertSee('Namukolo', escape: false);
        $response->assertSee('The Feeling', escape: false);
        $response->assertSee('Zambezi Riverside Pavilion', escape: false);
        $this->assertSame(3, substr_count($response->getContent(), 'class="db-story-row"'));
        $response->assertSee('data-inv-countdown', escape: false);
        $response->assertSee('db-gallery--count-6', escape: false);
        $response->assertSee('db-timeline', escape: false);
        $response->assertSee('data-rsvp-preview', escape: false);

        $response->assertSeeInOrder(
            ['id="top"', 'id="invitation"', 'id="story"', 'id="countdown"', 'id="gallery"', 'id="details"', 'id="programme"', 'id="rsvp"'],
            escape: false
        );
    }

    public function test_story_chapters_pair_paragraphs_with_portraits_and_fold_extras_into_the_last(): void
    {
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-midnight-gold')->firstOrFail();
        $event = $tpl->previewSampleEvent();

        $invitation = app(InvitationCustomizationService::class)->merge($event);
        $invitation['content']['story'] = "One.\n\nTwo.\n\n\nThree.\n\nFour.";

        $chapters = WeddingInvitationView::for($event, $invitation)->storyChapters();

        $this->assertCount(3, $chapters);
        $this->assertSame(['One.', 'Two.', "Three.\n\nFour."], array_column($chapters, 'text'));
        $this->assertSame($event->invitation_customization['media']['couple_photos'], array_column($chapters, 'photo'));

        $invitation['content']['story'] = '';
        $this->assertSame([], WeddingInvitationView::for($event, $invitation)->storyChapters());
    }

    public function test_preview_page_title_escapes_an_ampersand_once(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation')->firstOrFail();

        $this->actingAs($user)->get(route('templates.preview', $tpl))
            ->assertOk()
            ->assertSee('<title>Ivory &amp; Gold Wedding | Templates', escape: false)
            ->assertDontSee('&amp;amp;', escape: false);
    }

    public function test_preview_wedding_invitation_renders_ivory_gold_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-wedding-invitation', escape: false);
        $response->assertSee('wi-hero', escape: false);
        $response->assertSee('Kasuba', escape: false);
        $response->assertSee('Save the', escape: false);
        $response->assertSee('wi-timeline', escape: false);
        $response->assertSee('Garden Formal', escape: false);
        $response->assertSee('wi-details-section', escape: false);
        $response->assertSee('wi-countdown-section', escape: false);
        $response->assertSee('data-inv-countdown', escape: false);
    }

    public function test_preview_base_wedding_renders_text_only_markup(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'base-wedding')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-base-wedding', escape: false);
        $response->assertSee('bw-hero', escape: false);
        $response->assertSee('Chanda Phiri', escape: false);
        $response->assertSee('You are cordially invited', escape: false);
        $response->assertSee('bw-details-grid', escape: false);
        $response->assertSee('bw-programme', escape: false);
        $response->assertSee('Wedding ceremony', escape: false);
        $response->assertDontSee('images.unsplash.com/photo-', escape: false);
        $response->assertDontSee('default-event.png', escape: false);
        $response->assertDontSee('bw-gallery', escape: false);
    }

    public function test_preview_classic_renders_no_images(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-inv-std-hero', escape: false);
        $response->assertDontSee('evt-inv-hero-cover', escape: false);
        $response->assertDontSee('images.unsplash.com/photo-', escape: false);
    }

    public function test_preview_event_invite_renders_blush_card_markup(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'event-invite')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-event-invite', escape: false);
        $response->assertSee('ei-card', escape: false);
        $response->assertSee('Join Us For', escape: false);
        $response->assertSee('Denim and Brown', escape: false);
    }

    public function test_preview_botanical_graduation_renders_split_hero_markup(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee('evt-layout-botanical-graduation', escape: false);
        $response->assertSee('evt-bg-nav-strip', escape: false);
        $response->assertSee('photo-frame', escape: false);
    }

    public function test_preview_uses_the_requested_templates_merge_output_not_always_first_template(): void
    {
        $user = User::factory()->pro()->create();
        $botanical = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();
        $minimal = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $this->actingAs($user)->get(route('templates.preview', $botanical))
            ->assertOk()
            ->assertSee('evt-layout-botanical-graduation', escape: false);

        $this->actingAs($user)->get(route('templates.preview', $minimal))
            ->assertOk()
            ->assertDontSee('evt-layout-botanical-graduation', escape: false);
    }

    public function test_template_library_filters_by_category_and_search(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();
        $baseWedding = InvitationTemplate::query()->where('slug', 'base-wedding')->firstOrFail();

        $this->actingAs($user)->get(route('templates.index', ['category' => 'graduation']))
            ->assertOk()
            ->assertSee('Blush Celebration Card', escape: false)
            ->assertDontSee('<h2 class="tpl-gallery-title">'.$tpl->name.'</h2>', escape: false);

        $this->actingAs($user)->get(route('templates.index', ['category' => 'wedding']))
            ->assertOk()
            ->assertSee($baseWedding->name, escape: false)
            ->assertSee('<h2 class="tpl-gallery-title">'.$tpl->name.'</h2>', escape: false)
            ->assertDontSee('Blush Celebration Card', escape: false);

        $this->actingAs($user)->get(route('templates.index', ['q' => 'classic']))
            ->assertOk()
            ->assertSee($tpl->name, escape: false);
    }

    public function test_template_library_category_filter_applies_on_pick(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('templates.index'))
            ->assertOk()
            ->assertSee('js/templates-library.js', escape: false)
            ->assertSee('data-tpl-autosubmit', escape: false);
    }

    public function test_category_dropdown_only_lists_categories_with_templates_on_the_tab(): void
    {
        $user = User::factory()->create();
        $churchOption = '<option value="church"';

        // Categories nobody is tagged with never show.
        $this->actingAs($user)->get(route('templates.index'))
            ->assertOk()
            ->assertSee('<option value="wedding"', escape: false)
            ->assertSee('<option value="graduation"', escape: false)
            ->assertSee($churchOption, escape: false)
            ->assertDontSee('<option value="funeral-memorial"', escape: false)
            ->assertDontSee('<option value="baby-shower"', escape: false)
            ->assertDontSee('<option value="corporate"', escape: false)
            ->assertDontSee('<option value="birthday"', escape: false);

        // Beauty for Ashes (Pro) is the only church template.
        $this->actingAs($user)->get(route('templates.index', ['plan' => 'base']))
            ->assertOk()
            ->assertDontSee($churchOption, escape: false)
            ->assertSee('<option value="graduation"', escape: false);

        $this->actingAs($user)->get(route('templates.index', ['plan' => 'pro']))
            ->assertOk()
            ->assertSee($churchOption, escape: false);

        // A category whose only template is switched off disappears from every tab.
        InvitationTemplate::query()->where('slug', 'beauty-for-ashes')->update(['is_active' => false]);

        $this->actingAs($user)->get(route('templates.index', ['plan' => 'pro']))
            ->assertOk()
            ->assertDontSee($churchOption, escape: false);
    }

    public function test_a_category_with_nothing_on_the_tab_falls_back_to_all(): void
    {
        $user = User::factory()->create();

        // Church has no Base template.
        $this->actingAs($user)->get(route('templates.index', ['plan' => 'base', 'category' => 'church']))
            ->assertOk()
            ->assertSee('Wedding Standard', escape: false)
            ->assertDontSee('No templates match your filters.', escape: false);
    }

    public function test_template_library_plan_tabs_split_base_and_pro(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('templates.index'))
            ->assertOk()
            ->assertSee('tpl-plan-tabs', escape: false)
            ->assertSee('Wedding Standard', escape: false)
            ->assertSee('Ivory &amp; Gold Wedding', escape: false);

        $this->actingAs($user)->get(route('templates.index', ['plan' => 'base']))
            ->assertOk()
            ->assertSee('Wedding Standard', escape: false)
            ->assertDontSee('Ivory &amp; Gold Wedding', escape: false)
            ->assertSee('name="plan" value="base"', escape: false);

        $this->actingAs($user)->get(route('templates.index', ['plan' => 'pro']))
            ->assertOk()
            ->assertSee('Ivory &amp; Gold Wedding', escape: false)
            ->assertDontSee('<h2 class="tpl-gallery-title">Wedding Standard</h2>', escape: false);

        // Unknown plan values fall back to "All" rather than an empty grid.
        $this->actingAs($user)->get(route('templates.index', ['plan' => 'gold']))
            ->assertOk()
            ->assertSee('Wedding Standard', escape: false)
            ->assertSee('Ivory &amp; Gold Wedding', escape: false);
    }

    /**
     * Every template preview shows its real RSVP form, but one that cannot post anywhere.
     * Botanical has no inline form (guests pick a response first), so it shows its pills.
     */
    public function test_every_template_preview_shows_a_non_submitting_rsvp_form(): void
    {
        $user = User::factory()->proPlus()->create();
        $templates = InvitationTemplate::query()->where('is_active', true)->get();

        $this->assertNotEmpty($templates);

        foreach ($templates as $template) {
            $response = $this->actingAs($user)->get(route('templates.preview', $template));

            $response->assertOk();
            $response->assertSee('data-rsvp-preview', escape: false);
            $response->assertSee('Preview only', escape: false);
            $response->assertDontSee('publishing unlocks live links', escape: false);
            $response->assertDontSee('/rsvp"', escape: false);

            if ($template->slug === 'graduation-template-2-botanical-blush') {
                $response->assertSee('Will you be attending?', escape: false);
                $response->assertSee('evt-bg-rsvp-choice-btn--preview', escape: false);
            } else {
                $response->assertSee('name="status"', escape: false);
                $response->assertSee('rsvp-public.css', escape: false);
            }
        }
    }

    public function test_event_preview_shows_a_non_submitting_rsvp_form(): void
    {
        $user = User::factory()->proPlus()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'event_date' => now()->addMonth(),
            'rsvp_deadline' => now()->addWeeks(2),
        ]);

        $response = $this->actingAs($user)->get(route('events.preview', $event));

        $response->assertOk();
        $response->assertSee('data-rsvp-preview', escape: false);
        $response->assertSee('name="status"', escape: false);
        $response->assertSee('Preview only', escape: false);
        $response->assertDontSee('publishing unlocks live links', escape: false);
        $response->assertDontSee('/e/'.$event->slug.'/rsvp', escape: false);
    }

    public function test_owner_can_save_invitation_design(): void
    {
        // Pro+ so the theme_palette assertions below actually apply.
        $user = User::factory()->proPlus()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();

        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
            'invitation_customization' => null,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();
        $visibility = [];
        foreach ($order as $type) {
            $visibility[$type] = '1';
        }

        $response = $this->actingAs($user)->patch(route('events.invitation-design.update', $event), [
            'theme_palette' => 'slate-sky',
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'countdown_enabled' => '1',
            'section_order' => $order,
            'section_visible' => $visibility,
            'clear_video' => '0',
            'clear_audio' => '0',
            'content_story' => '',
            'schedule_items' => [],
            'rsvp_form' => [
                'message' => ['visible' => '1', 'label' => 'Message to host'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'invitation-design-saved');

        $event->refresh();
        $this->assertIsArray($event->invitation_customization);
        $this->assertSame('slate-sky', $event->invitation_customization['theme']['palette_key']);
        $this->assertSame(
            InvitationPalettes::get('slate-sky')['primary'],
            $event->invitation_customization['theme']['primary']
        );
        $this->assertSame($order, collect($event->invitation_customization['sections'])->pluck('type')->values()->all());
    }

    public function test_intruder_cannot_update_invitation_design(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $tpl = InvitationTemplate::query()->firstOrFail();

        $event = Event::factory()->for($owner)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $order = collect($tpl->default_sections)->pluck('type')->values()->all();

        $response = $this->actingAs($intruder)->patch(route('events.invitation-design.update', $event), [
            'theme_palette' => 'slate-sky',
            'font_heading_key' => 'inter',
            'font_body_key' => 'inter',
            'animation_subtle' => '0',
            'section_order' => $order,
            'section_visible' => array_fill_keys($order, '1'),
            'clear_video' => '0',
            'clear_audio' => '0',
        ]);

        $response->assertForbidden();
    }
}
