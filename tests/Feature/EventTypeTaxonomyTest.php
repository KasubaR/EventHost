<?php

namespace Tests\Feature;

use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 of plans/public-private-portals.md: private event types are derived
 * from the template library instead of a hardcoded list; public types stay a
 * plain constant. Categories are seeded by InvitationTemplateSeeder (see
 * TestCase::$seed), so this exercises the real seeded data, not a fixture.
 */
class EventTypeTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_event_types_match_todays_seven_categories(): void
    {
        $this->assertSame(
            Event::INVITATION_EVENT_TYPES,
            Event::privateEventTypes()
        );
    }

    public function test_public_event_types_is_the_same_list_as_ticketed_event_types(): void
    {
        $this->assertSame(Event::TICKETED_EVENT_TYPES, Event::PUBLIC_EVENT_TYPES);
    }

    public function test_event_types_for_invitation_is_unchanged_by_the_refactor(): void
    {
        $this->assertSame(
            Event::INVITATION_EVENT_TYPES,
            Event::eventTypesFor(EventProductKind::Invitation)
        );
    }

    public function test_event_types_for_ticketed_is_unchanged_by_the_refactor(): void
    {
        $this->assertSame(
            Event::TICKETED_EVENT_TYPES,
            Event::eventTypesFor(EventProductKind::Ticketed)
        );
    }

    public function test_a_category_with_no_active_template_is_not_offered(): void
    {
        InvitationTemplate::query()->whereHas('categories', fn ($q) => $q->where('slug', 'church'))
            ->update(['is_active' => false]);

        $this->assertNotContains('church', Event::privateEventTypes());
    }

    public function test_a_category_reactivated_by_a_new_template_reappears(): void
    {
        InvitationTemplate::query()->whereHas('categories', fn ($q) => $q->where('slug', 'church'))
            ->update(['is_active' => false]);
        $this->assertNotContains('church', Event::privateEventTypes());

        $category = InvitationTemplateCategory::query()->where('slug', 'church')->firstOrFail();
        $template = InvitationTemplate::factory()->create(['is_active' => true]);
        $template->categories()->attach($category->id);

        $this->assertContains('church', Event::privateEventTypes());
    }

    public function test_a_category_slug_with_no_map_entry_is_skipped_not_guessed(): void
    {
        $category = InvitationTemplateCategory::query()->create(['slug' => 'anniversary', 'name' => 'Anniversary', 'sort_order' => 99]);
        $template = InvitationTemplate::factory()->create(['is_active' => true]);
        $template->categories()->attach($category->id);

        $this->assertNotContains('anniversary', Event::privateEventTypes());
        $this->assertSame(Event::INVITATION_EVENT_TYPES, Event::privateEventTypes());
    }

    public function test_falls_back_to_the_static_list_when_no_categories_have_active_templates(): void
    {
        InvitationTemplate::query()->update(['is_active' => false]);

        $this->assertSame(Event::INVITATION_EVENT_TYPES, Event::privateEventTypes());
    }

    public function test_a_stored_private_type_with_no_matching_template_still_validates_for_that_event(): void
    {
        // eventTypesFor()'s $includeCurrent grandfathering, exercised through the
        // template-derived list instead of the old static one.
        InvitationTemplate::query()->whereHas('categories', fn ($q) => $q->where('slug', 'church'))
            ->update(['is_active' => false]);

        $types = Event::eventTypesFor(EventProductKind::Invitation, 'church');

        $this->assertContains('church', $types);
        $this->assertNotContains('church', Event::privateEventTypes());
    }

    public function test_every_seven_categories_map_to_a_type(): void
    {
        // Guards the explicit slug -> type map itself: if a category slug is
        // ever renamed without updating CATEGORY_SLUG_TO_TYPE, it silently
        // vanishes from the private type list instead of erroring.
        $slugs = InvitationTemplateCategory::query()->pluck('slug')->all();

        $this->assertEqualsCanonicalizing($slugs, array_keys(Event::CATEGORY_SLUG_TO_TYPE));
    }
}
