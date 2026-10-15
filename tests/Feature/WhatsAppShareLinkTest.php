<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\WhatsAppShareService;
use App\Support\WhatsAppInviteLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The free wa.me share link (plans/whatsapp-sharing-gaps.md Phase 1): opening it is recorded, it is refused where it would
 * lead nowhere, and the bulk action is a queue of chats rather than a "mark everyone sent".
 */
class WhatsAppShareLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->event = Event::factory()->for($this->owner)->published()->create(['name' => 'Mary & Tom 🎉 #1 + friends']);
    }

    public function test_opening_the_share_link_marks_the_guest_sent_logs_it_and_redirects_to_whatsapp(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['name' => 'Zoë 👨‍👩‍👧 Banda', 'phone' => '+260 97 123 4567']);

        $response = $this->actingAs($this->owner)->get(route('events.guests.whatsapp-share', [$this->event, $guest]));

        $prefix = 'https://api.whatsapp.com/send?phone=260971234567&text=';
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith($prefix, $location);

        $expected = WhatsAppInviteLink::invitationMessage($guest->name, $this->event->name, (string) $guest->personalRsvpUrl());
        $this->assertSame($expected, rawurldecode(substr($location, strlen($prefix))));
        $this->assertStringContainsString('%F0%9F%8E%89', $location, 'the emoji travels as its own UTF-8 bytes');
        $this->assertStringContainsString("\n", $expected);

        $this->assertTrue((bool) $guest->fresh()->invitation_sent);
        $this->assertNotNull($guest->fresh()->invitation_sent_at);
        $this->assertDatabaseHas('notification_logs', [
            'event_id' => $this->event->id,
            'guest_id' => $guest->id,
            'channel' => WhatsAppShareService::LOG_CHANNEL,
            'type' => WhatsAppShareService::LOG_TYPE,
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    public function test_the_guest_list_offers_share_again_and_the_message_to_copy(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->actingAs($this->owner)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Share on WhatsApp')
            ->assertDontSee('Share again on WhatsApp')
            ->assertSee('Copy invitation message');

        $this->get(route('events.guests.whatsapp-share', [$this->event, $guest]));

        $this->get(route('events.guests.index', $this->event))->assertSee('Share again on WhatsApp');
    }

    public function test_the_share_link_is_disabled_and_refused_on_an_unpublished_event(): void
    {
        $this->event->forceFill(['is_published' => false])->save();
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->actingAs($this->owner)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Publish the event first.')
            ->assertDontSee(route('events.guests.whatsapp-share', [$this->event, $guest]), false);

        $this->get(route('events.guests.whatsapp-share', [$this->event, $guest]))
            ->assertRedirect(route('events.guests.index', $this->event))
            ->assertSessionHasErrors('whatsapp_share');

        $this->assertFalse((bool) $guest->fresh()->invitation_sent);
        $this->assertDatabaseMissing('notification_logs', ['guest_id' => $guest->id]);
    }

    public function test_the_share_link_is_refused_while_rsvp_is_closed(): void
    {
        $this->event->forceFill(['rsvp_closed_at' => now()])->save();
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->actingAs($this->owner)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertDontSee(route('events.guests.whatsapp-share', [$this->event, $guest]), false);

        $this->get(route('events.guests.whatsapp-share', [$this->event, $guest]))->assertSessionHasErrors('whatsapp_share');
        $this->assertFalse((bool) $guest->fresh()->invitation_sent);
    }

    public function test_a_guest_without_a_phone_is_refused_with_the_reason(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => null]);

        $this->actingAs($this->owner)->get(route('events.guests.whatsapp-share', [$this->event, $guest]))
            ->assertSessionHasErrors(['whatsapp_share' => $guest->name.': No phone number. Copy their invitation message and send it another way.']);
    }

    public function test_someone_else_cannot_open_the_share_link(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->actingAs(User::factory()->create())
            ->get(route('events.guests.whatsapp-share', [$this->event, $guest]))
            ->assertForbidden();

        $this->assertFalse((bool) $guest->fresh()->invitation_sent);
    }

    public function test_the_queue_counts_chats_opened_since_it_started(): void
    {
        $first = Guest::factory()->for($this->event)->create(['name' => 'Alice', 'phone' => '0971234567']);
        $second = Guest::factory()->for($this->event)->create(['name' => 'Bob', 'phone' => '0977654321']);

        $this->actingAs($this->owner)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'prepare_whatsapp_share', 'guest_ids' => [$first->id, $second->id]])
            ->assertRedirect(route('events.guests.whatsapp-queue', $this->event));

        $this->assertFalse((bool) $first->fresh()->invitation_sent);
        $this->assertFalse((bool) $second->fresh()->invitation_sent);

        $this->get(route('events.guests.whatsapp-queue', $this->event))->assertSeeText('0 of 2 chats opened');

        $this->travel(2)->seconds();
        $this->get(route('events.guests.whatsapp-share', [$this->event, $first]));

        $this->get(route('events.guests.whatsapp-queue', $this->event))->assertSeeText('1 of 2 chats opened');
        $this->assertTrue((bool) $first->fresh()->invitation_sent);
        $this->assertFalse((bool) $second->fresh()->invitation_sent);
    }

    public function test_the_queue_without_a_selection_sends_the_host_back_to_the_list(): void
    {
        $this->actingAs($this->owner)->get(route('events.guests.whatsapp-queue', $this->event))
            ->assertRedirect(route('events.guests.index', $this->event))
            ->assertSessionHasErrors('action');
    }

    public function test_the_bulk_share_is_refused_on_an_unpublished_event(): void
    {
        $this->event->forceFill(['is_published' => false])->save();
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->actingAs($this->owner)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'prepare_whatsapp_share', 'guest_ids' => [$guest->id]])
            ->assertSessionHasErrors('action');
    }

    public function test_the_api_returns_share_links_and_marks_nobody_sent(): void
    {
        $reachable = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $landline = Guest::factory()->for($this->event)->create(['phone' => '0211123456']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->owner->createToken('t')->plainTextToken)
            ->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", [
                'action' => 'prepare_whatsapp_share',
                'guest_ids' => [$reachable->id, $landline->id],
            ])
            ->assertOk()
            ->assertJsonPath('affected_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('share_links.0.guest_id', $reachable->id)
            ->assertJsonPath('skipped.0.guest_id', $landline->id)
            ->assertJsonPath('skipped.0.reason', 'Landline: WhatsApp cannot reach it.');

        $this->assertStringStartsWith('https://api.whatsapp.com/send?phone=260971234567&text=', (string) $response->json('share_links.0.url'));
        $this->assertFalse((bool) $reachable->fresh()->invitation_sent);
    }

    public function test_the_api_refuses_share_links_for_an_unpublished_event(): void
    {
        $this->event->forceFill(['is_published' => false])->save();
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->withHeader('Authorization', 'Bearer '.$this->owner->createToken('t')->plainTextToken)
            ->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => 'prepare_whatsapp_share', 'guest_ids' => [$guest->id]])
            ->assertStatus(422)
            ->assertJsonPath('error', 'unpublished');
    }
}
