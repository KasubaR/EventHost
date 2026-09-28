<?php

namespace Tests\Feature;

use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Private event types: the static INVITATION_EVENT_TYPES list is always offered,
 * whether or not a template is tagged with the matching category (templates carry
 * one category each for now). Public types stay a plain constant. Categories are
 * seeded by InvitationTemplateSeeder (see TestCase::$seed), so this exercises the
 * real seeded data, not a fixture.
 */
class EventTypeTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_event_types_are_the_seven_static_types(): void
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

    public function test_event_types_for_invitation_is_the_private_list(): void
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

    public function test_a_type_with_no_template_in_its_category_is_still_offered(): void
    {
        $withTemplates = InvitationTemplateCategory::query()
            ->whereHas('invitationTemplates', fn ($q) => $q->where('is_active', true))
            ->pluck('slug')
            ->all();

        // Funeral/Memorial has no template of its own today.
        $this->assertNotContains('funeral-memorial', $withTemplates);
        $this->assertContains('funeral', Event::privateEventTypes());
    }

    public function test_every_type_stays_offered_when_no_template_is_active(): void
    {
        InvitationTemplate::query()->update(['is_active' => false]);

        $this->assertSame(Event::INVITATION_EVENT_TYPES, Event::privateEventTypes());
    }

    public function test_a_category_slug_with_no_map_entry_is_skipped_not_guessed(): void
    {
        $category = InvitationTemplateCategory::query()->create(['slug' => 'anniversary', 'name' => 'Anniversary', 'sort_order' => 99]);
        $template = InvitationTemplate::factory()->create(['is_active' => true]);
        $template->categories()->attach($category->id);

        $this->assertNotContains('anniversary', Event::privateEventTypes());
        $this->assertSame(Event::INVITATION_EVENT_TYPES, Event::privateEventTypes());
    }

    public function test_a_stored_private_type_outside_the_list_still_validates_for_that_event(): void
    {
        // eventTypesFor()'s $includeCurrent grandfathering.
        $types = Event::eventTypesFor(EventProductKind::Invitation, 'kitchen_party');

        $this->assertContains('kitchen_party', $types);
        $this->assertNotContains('kitchen_party', Event::privateEventTypes());
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
