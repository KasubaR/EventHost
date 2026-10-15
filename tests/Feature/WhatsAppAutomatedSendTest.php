<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\CommunicationService;
use App\Services\WhatsAppService;
use App\Support\ZambianPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * The paid Twilio send (plans/whatsapp-sharing-gaps.md Phase 2): the same numbers as the share link, no second bill for a
 * repeat click, delivery reported back, and a cap that only counts invitations.
 */
class WhatsAppAutomatedSendTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Event $event;

    /** @var WhatsAppService&object{calls: int, to: list<string>} */
    private WhatsAppService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('communications.whatsapp.enabled', true);
        config()->set('services.twilio.auth_token', 'test-auth-token');

        $this->fake = new class implements WhatsAppService
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $to = [];

            public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
            {
                $this->calls++;
                $this->to[] = $toE164Phone;

                return ['status' => 'sent', 'provider_message_id' => 'SM'.$this->calls, 'response' => null];
            }

            public function sendText(string $toE164Phone, string $body): array
            {
                return ['status' => 'skipped', 'provider_message_id' => null, 'response' => null];
            }

            public function sendMedia(string $toE164Phone, string $mediaUrl, ?string $caption = null): array
            {
                return ['status' => 'skipped', 'provider_message_id' => null, 'response' => null];
            }
        };
        $this->app->instance(WhatsAppService::class, $this->fake);

        $this->owner = User::factory()->pro()->create();
        $this->event = Event::factory()->for($this->owner)->published()->create();
    }

    private function send(Guest $guest, array $body = [])
    {
        return $this->actingAs($this->owner)
            ->post(route('events.guests.whatsapp-invite', ['event' => $this->event, 'guest' => $guest]), $body);
    }

    // ── Which numbers ────────────────────────────────────────────────────────

    public function test_the_00260_and_bracketed_trunk_forms_are_zambian_mobiles(): void
    {
        $this->assertSame('+260971234567', ZambianPhone::toE164('00260 97 123 4567'));
        $this->assertSame('+260971234567', ZambianPhone::toE164('+260 (0) 97 123 4567'));
        $this->assertSame('+260971234567', ZambianPhone::toE164('971234567'));
        $this->assertNull(ZambianPhone::toE164('+44 7700 900123'));
        $this->assertTrue(ZambianPhone::isInternationalForShare('+44 7700 900123'));
        $this->assertFalse(ZambianPhone::isInternationalForShare('0211123456'), 'a landline cannot be shared either');
    }

    public function test_a_number_written_with_00260_sends(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '00260 97 123 4567']);

        $this->send($guest)->assertSessionHas('status', 'guest-whatsapp-sent');

        $this->assertSame(['+260971234567'], $this->fake->to);
    }

    public function test_an_international_number_is_refused_and_pointed_at_the_share_link(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '+44 7700 900123']);

        $this->send($guest)->assertSessionHas('status', 'guest-whatsapp-invalid-phone');
        $this->assertSame(0, $this->fake->calls);

        $this->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Automated WhatsApp is for Zambian mobile numbers. Use Share on WhatsApp instead.');
    }

    // ── Sending twice ────────────────────────────────────────────────────────

    public function test_a_second_send_within_a_day_is_refused_until_the_host_says_send_again(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->send($guest)->assertSessionHas('status', 'guest-whatsapp-sent');

        $this->travel(5)->minutes();
        $this->send($guest)
            ->assertSessionMissing('status')
            ->assertSessionHas('whatsapp_already_sent.guest_id', $guest->id)
            ->assertSessionHas('whatsapp_already_sent.in_flight', false);
        $this->assertSame(1, $this->fake->calls);

        $this->get(route('events.guests.index', $this->event))
            ->assertSee('was already sent a WhatsApp invitation')
            ->assertSee('Send again');

        $this->send($guest, ['resend' => '1'])->assertSessionHas('status', 'guest-whatsapp-sent');
        $this->assertSame(2, $this->fake->calls);
    }

    public function test_after_a_day_a_send_goes_through_without_send_again(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);

        $this->send($guest);
        $this->travel(25)->hours();
        $this->send($guest)->assertSessionHas('status', 'guest-whatsapp-sent');

        $this->assertSame(2, $this->fake->calls);
    }

    public function test_a_send_still_in_flight_blocks_even_send_again(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        NotificationLog::query()->create([
            'event_id' => $this->event->id,
            'guest_id' => $guest->id,
            'channel' => 'whatsapp',
            'type' => CommunicationService::WHATSAPP_INVITATION_TYPE,
            'status' => NotificationLog::STATUS_PENDING,
        ]);

        $this->send($guest, ['resend' => '1'])->assertSessionHas('whatsapp_already_sent.in_flight', true);
        $this->assertSame(0, $this->fake->calls);

        $this->travel(3)->minutes();
        $this->send($guest, ['resend' => '1'])->assertSessionHas('status', 'guest-whatsapp-sent');
        $this->assertSame(1, $this->fake->calls);
    }

    public function test_an_undelivered_send_does_not_count_as_sent(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $this->send($guest);
        NotificationLog::query()->where('guest_id', $guest->id)->update(['delivery_status' => 'failed', 'error_code' => '63003']);

        $this->send($guest)->assertSessionHas('status', 'guest-whatsapp-sent');
        $this->assertSame(2, $this->fake->calls);
    }

    public function test_the_api_answers_409_already_sent_and_accepts_resend(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $token = $this->owner->createToken('t')->plainTextToken;
        $url = route('api.v1.host.events.guests.whatsapp-invite', [$this->event, $guest]);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson($url)->assertOk()->assertJsonPath('outcome', 'sent');

        $this->travel(5)->minutes();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('error', 'already_sent')
            ->assertJsonPath('in_flight', false);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson($url, ['resend' => true])
            ->assertOk()
            ->assertJsonPath('outcome', 'sent');
        $this->assertSame(2, $this->fake->calls);
    }

    // ── Delivery status ──────────────────────────────────────────────────────

    /** @param  array<string, string>  $params */
    private function postStatus(array $params, bool $signed = true)
    {
        $url = route('webhooks.twilio.whatsapp.status');
        $headers = $signed
            ? ['X-Twilio-Signature' => (new RequestValidator('test-auth-token'))->computeSignature($url, $params)]
            : [];

        return $this->withHeaders($headers)->post($url, $params);
    }

    public function test_an_unsigned_status_callback_is_refused(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $this->send($guest);

        $this->postStatus(['MessageSid' => 'SM1', 'MessageStatus' => 'failed', 'ErrorCode' => '63003'], signed: false)
            ->assertForbidden();

        $this->assertNull(NotificationLog::query()->where('provider_message_id', 'SM1')->value('delivery_status'));
        $this->assertTrue((bool) $guest->fresh()->invitation_sent);
    }

    public function test_a_number_not_on_whatsapp_unmarks_the_guest_and_the_list_says_why(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $this->send($guest);
        $this->assertTrue((bool) $guest->fresh()->invitation_sent);

        $this->postStatus(['MessageSid' => 'SM1', 'MessageStatus' => 'failed', 'ErrorCode' => '63003', 'To' => 'whatsapp:+260971234567'])
            ->assertOk();

        $log = NotificationLog::query()->where('provider_message_id', 'SM1')->firstOrFail();
        $this->assertSame('failed', $log->delivery_status);
        $this->assertSame('63003', $log->error_code);
        $this->assertFalse((bool) $guest->fresh()->invitation_sent);
        $this->assertNull($guest->fresh()->invitation_sent_at);

        $this->actingAs($this->owner)->get(route('events.guests.index', $this->event))
            ->assertSee("WhatsApp not delivered: This number isn't on WhatsApp");
    }

    public function test_a_late_status_never_moves_a_message_backwards(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $this->send($guest);

        $this->postStatus(['MessageSid' => 'SM1', 'MessageStatus' => 'delivered'])->assertOk();
        $this->postStatus(['MessageSid' => 'SM1', 'MessageStatus' => 'sent'])->assertOk();

        $this->assertSame('delivered', NotificationLog::query()->where('provider_message_id', 'SM1')->value('delivery_status'));
        $this->assertTrue((bool) $guest->fresh()->invitation_sent);
    }

    public function test_a_failure_keeps_a_sent_mark_the_host_set_afterwards(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $this->send($guest);

        $this->travel(10)->minutes();
        $guest->forceFill(['invitation_sent' => true, 'invitation_sent_at' => now()])->save();

        $this->postStatus(['MessageSid' => 'SM1', 'MessageStatus' => 'undelivered', 'ErrorCode' => '63024'])->assertOk();

        $this->assertTrue((bool) $guest->fresh()->invitation_sent);
    }

    // ── The hourly cap ───────────────────────────────────────────────────────

    public function test_an_invitation_burst_does_not_rate_limit_an_rsvp_confirmation(): void
    {
        config()->set('communications.whatsapp.hourly_cap_per_event', 1);
        config()->set('services.twilio.rsvp_confirmation_content_sid', 'HXconfirm');

        $invited = Guest::factory()->for($this->event)->create(['phone' => '0971234567']);
        $this->send($invited)->assertSessionHas('status', 'guest-whatsapp-sent');

        $other = Guest::factory()->for($this->event)->create(['phone' => '0977654321']);
        $this->send($other)->assertSessionHas('status', 'guest-whatsapp-rate-limited');

        $replying = Guest::factory()->for($this->event)->create(['phone' => '0961234567']);
        $rsvp = Rsvp::create([
            'event_id' => $this->event->id,
            'guest_id' => $replying->id,
            'status' => 'accepted',
            'attendee_count' => 1,
        ]);

        $this->assertSame('sent', app(CommunicationService::class)->sendWhatsAppRsvpConfirmation($this->event, $replying, $rsvp));
    }
}
