<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPublishSavesPendingEditsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function formPayload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => '18:00',
        ], $overrides);
    }

    public function test_publishing_saves_the_edits_sitting_in_the_form(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'name' => 'Original draft name',
            'is_published' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->formPayload($event, [
                'name' => 'Edited before publishing',
                'publish' => '1',
            ]))
            ->assertRedirect(route('events.public', $event->fresh()->slug));

        $event->refresh();

        $this->assertSame('Edited before publishing', $event->name);
        $this->assertTrue($event->is_published, 'Event should be published.');
    }

    public function test_saving_without_the_publish_flag_leaves_the_event_a_draft(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'name' => 'Original draft name',
            'is_published' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->formPayload($event, [
                'name' => 'Saved but not published',
            ]));

        $event->refresh();

        $this->assertSame('Saved but not published', $event->name);
        $this->assertFalse($event->is_published, 'Saving alone must not publish.');
    }

    public function test_publish_is_rejected_when_the_edited_data_is_invalid(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'name' => 'Original draft name',
            'is_published' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('events.update', $event), $this->formPayload($event, [
                'name' => '',
                'publish' => '1',
            ]))
            ->assertSessionHasErrors('name');

        $event->refresh();

        $this->assertSame('Original draft name', $event->name);
        $this->assertFalse($event->is_published, 'Invalid data must not publish the event.');
    }

    public function test_edit_page_publish_button_targets_the_event_form(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('id="event-update-form"', escape: false);
        $response->assertSee('form="event-update-form" name="publish"', escape: false);
    }
}
