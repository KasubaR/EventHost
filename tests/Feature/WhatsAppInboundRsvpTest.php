<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

class WhatsAppInboundRsvpTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_TOKEN = 'test_twilio_auth_token_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.twilio.auth_token', self::AUTH_TOKEN);
        config()->set('communications.whatsapp.enabled', true);
        Notification::fake();
    }

    public function test_accepted_quick_reply_sends_entry_pass_media_for_pro_host(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedInvite('SM_OUT_ACCEPT');

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_ACCEPT',
            'From' => 'whatsapp:+260971234567',
            'ButtonPayload' => 'rsvp_accepted',
            'OriginalRepliedMessageSid' => 'SM_OUT_ACCEPT',
        ])->assertOk();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertNotNull($rsvp);
        $this->assertSame(RsvpStatus::Accepted, $rsvp->status);
        $this->assertSame(1, $rsvp->attendee_count);
        $this->assertSame(0, $fake->textCalls);
        $this->assertSame(1, $fake->mediaCalls);
        $this->assertSame($guest->entryPassPngUrl(), $fake->lastMediaUrl);
        $this->assertStringContainsString('Thank you', (string) $fake->lastMediaCaption);
        $this->assertStringContainsString('entry pass', (string) $fake->lastMediaCaption);
        $this->assertDatabaseHas('notification_logs', [
            'guest_id' => $guest->id,
            'type' => 'guest_rsvp_whatsapp_inbound',
            'provider_message_id' => 'SM_IN_ACCEPT',
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    public function test_accepted_without_premium_tools_sends_text_only(): void
    {
        $fake = $this->bindWhatsAppFake();
        // Base tier — hasEntryPassFor is false even when Accepted.
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create([
            'phone' => '+260971234567',
            'invitation_token' => str_repeat('b', 48),
        ]);
        NotificationLog::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'channel' => 'whatsapp',
            'type' => 'guest_invitation_whatsapp',
            'status' => NotificationLog::STATUS_SENT,
            'provider_message_id' => 'SM_OUT_BASE',
            'sent_at' => now(),
        ]);

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_BASE',
            'From' => 'whatsapp:+260971234567',
            'ButtonPayload' => 'rsvp_accepted',
            'OriginalRepliedMessageSid' => 'SM_OUT_BASE',
        ])->assertOk();

        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp?->status);
        $this->assertSame(1, $fake->textCalls);
        $this->assertSame(0, $fake->mediaCalls);
        $this->assertStringContainsString('Thank you', $fake->texts[0] ?? '');
    }

    public function test_declined_and_maybe_payloads_are_text_only(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guestDecline] = $this->seedInvite('SM_OUT_DEC', phone: '+260971111111');
        $guestMaybe = Guest::factory()->for($event)->create([
            'phone' => '+260972222222',
            'invitation_token' => str_repeat('m', 48),
        ]);
        NotificationLog::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guestMaybe->id,
            'channel' => 'whatsapp',
            'type' => 'guest_invitation_whatsapp',
            'status' => NotificationLog::STATUS_SENT,
            'provider_message_id' => 'SM_OUT_MAYBE',
            'sent_at' => now(),
        ]);

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_DEC',
            'From' => 'whatsapp:+260971111111',
            'ButtonPayload' => 'rsvp_declined',
            'OriginalRepliedMessageSid' => 'SM_OUT_DEC',
        ])->assertOk();

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_MAYBE',
            'From' => 'whatsapp:+260972222222',
            'ButtonPayload' => 'rsvp_maybe',
            'OriginalRepliedMessageSid' => 'SM_OUT_MAYBE',
        ])->assertOk();

        $this->assertSame(RsvpStatus::Declined, $guestDecline->fresh()->rsvp?->status);
        $this->assertSame(0, $guestDecline->fresh()->rsvp?->attendee_count);
        $this->assertSame(RsvpStatus::Maybe, $guestMaybe->fresh()->rsvp?->status);
        $this->assertSame(0, $guestMaybe->fresh()->rsvp?->attendee_count);
        $this->assertSame(2, $fake->textCalls);
        $this->assertSame(0, $fake->mediaCalls);
    }

    public function test_invalid_signature_is_forbidden(): void
    {
        $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedInvite('SM_OUT_BAD');

        $this->post(route('webhooks.twilio.whatsapp'), [
            'MessageSid' => 'SM_IN_BAD',
            'From' => 'whatsapp:+260971234567',
            'ButtonPayload' => 'rsvp_accepted',
            'OriginalRepliedMessageSid' => 'SM_OUT_BAD',
        ], [
            'X-Twilio-Signature' => 'invalid',
        ])->assertForbidden();

        $this->assertNull($guest->fresh()->rsvp);
    }

    public function test_unknown_body_is_ignored(): void
    {
        $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedInvite('SM_OUT_UNK');

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_UNK',
            'From' => 'whatsapp:+260971234567',
            'Body' => 'hello there',
            'OriginalRepliedMessageSid' => 'SM_OUT_UNK',
        ])->assertOk();

        $this->assertNull($guest->fresh()->rsvp);
    }

    public function test_closed_rsvp_does_not_write(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedInvite('SM_OUT_CLOSED', rsvpDeadline: now()->subDay());

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_CLOSED',
            'From' => 'whatsapp:+260971234567',
            'ButtonPayload' => 'rsvp_accepted',
            'OriginalRepliedMessageSid' => 'SM_OUT_CLOSED',
        ])->assertOk();

        $this->assertNull($guest->fresh()->rsvp);
        $this->assertGreaterThanOrEqual(1, $fake->textCalls);
    }

    public function test_guest_limit_full_does_not_write_accepted(): void
    {
        $fake = $this->bindWhatsAppFake();
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(['guest_limit' => 1]);
        $filler = Guest::factory()->for($event)->create();
        Rsvp::query()->create([
            'event_id' => $event->id,
            'guest_id' => $filler->id,
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 1,
        ]);

        $guest = Guest::factory()->for($event)->create([
            'phone' => '+260971234567',
            'invitation_token' => str_repeat('f', 48),
        ]);
        NotificationLog::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'channel' => 'whatsapp',
            'type' => 'guest_invitation_whatsapp',
            'status' => NotificationLog::STATUS_SENT,
            'provider_message_id' => 'SM_OUT_FULL',
            'sent_at' => now(),
        ]);

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_FULL',
            'From' => 'whatsapp:+260971234567',
            'ButtonPayload' => 'rsvp_accepted',
            'OriginalRepliedMessageSid' => 'SM_OUT_FULL',
        ])->assertOk();

        $this->assertNull($guest->fresh()->rsvp);
        $this->assertGreaterThanOrEqual(1, $fake->textCalls);
    }

    public function test_ambiguous_phone_without_original_sid_does_not_write(): void
    {
        $this->bindWhatsAppFake();
        $phone = '+260971234567';
        $ownerA = User::factory()->pro()->create();
        $ownerB = User::factory()->pro()->create();
        $eventA = Event::factory()->for($ownerA)->create();
        $eventB = Event::factory()->for($ownerB)->create();
        $guestA = Guest::factory()->for($eventA)->create(['phone' => $phone]);
        $guestB = Guest::factory()->for($eventB)->create(['phone' => $phone]);

        foreach ([[$eventA, $guestA, 'SM_A'], [$eventB, $guestB, 'SM_B']] as [$event, $guest, $sid]) {
            NotificationLog::query()->create([
                'event_id' => $event->id,
                'guest_id' => $guest->id,
                'channel' => 'whatsapp',
                'type' => 'guest_invitation_whatsapp',
                'status' => NotificationLog::STATUS_SENT,
                'provider_message_id' => $sid,
                'sent_at' => now(),
            ]);
        }

        $this->postSignedWebhook([
            'MessageSid' => 'SM_IN_AMB',
            'From' => 'whatsapp:'.$phone,
            'ButtonPayload' => 'rsvp_accepted',
        ])->assertOk();

        $this->assertNull($guestA->fresh()->rsvp);
        $this->assertNull($guestB->fresh()->rsvp);
    }

    public function test_duplicate_inbound_message_sid_is_idempotent(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedInvite('SM_OUT_IDEM');

        $payload = [
            'MessageSid' => 'SM_IN_IDEM',
            'From' => 'whatsapp:+260971234567',
            'ButtonPayload' => 'rsvp_accepted',
            'OriginalRepliedMessageSid' => 'SM_OUT_IDEM',
        ];

        $this->postSignedWebhook($payload)->assertOk();
        $mediaAfterFirst = $fake->mediaCalls;
        $this->postSignedWebhook($payload)->assertOk();

        $this->assertSame(1, Rsvp::query()->where('guest_id', $guest->id)->count());
        $this->assertSame($mediaAfterFirst, $fake->mediaCalls);
    }

    public function test_entry_pass_png_route_returns_png_when_eligible(): void
    {
        [$event, $guest] = $this->seedInvite('SM_OUT_PNG');
        Rsvp::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 1,
        ]);

        $this->get(route('rsvp.token.entry-pass-png', $guest->invitation_token))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_entry_pass_png_route_404s_when_not_accepted(): void
    {
        [$event, $guest] = $this->seedInvite('SM_OUT_PNG404');

        $this->get(route('rsvp.token.entry-pass-png', $guest->invitation_token))
            ->assertNotFound();
    }

    public function test_invite_header_path_uses_cover_or_default(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('events/cover-test.png', 'fake-png-bytes');

        $owner = User::factory()->pro()->create();
        $withCover = Event::factory()->for($owner)->create(['cover_image' => 'events/cover-test.png']);
        $without = Event::factory()->for($owner)->create(['cover_image' => null]);

        $this->assertSame('storage/events/cover-test.png', $withCover->whatsAppInviteHeaderMediaPath());
        $this->assertSame('images/default-event-wa.jpg', $without->whatsAppInviteHeaderMediaPath());
        $this->assertStringEndsWith('/images/default-event-wa.jpg', $without->whatsAppInviteHeaderMediaUrl());
    }

    /**
     * @return object{textCalls: int, mediaCalls: int, lastMediaUrl: ?string, lastMediaCaption: ?string, texts: list<string>}&WhatsAppService
     */
    private function bindWhatsAppFake(): object
    {
        $fake = new class implements WhatsAppService
        {
            public int $textCalls = 0;

            public int $mediaCalls = 0;

            public ?string $lastMediaUrl = null;

            public ?string $lastMediaCaption = null;

            /** @var list<string> */
            public array $texts = [];

            public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
            {
                return ['status' => 'sent', 'provider_message_id' => 'SM_TPL', 'response' => null];
            }

            public function sendText(string $toE164Phone, string $body): array
            {
                $this->textCalls++;
                $this->texts[] = $body;

                return ['status' => 'sent', 'provider_message_id' => 'SM_TXT_'.$this->textCalls, 'response' => null];
            }

            public function sendMedia(string $toE164Phone, string $mediaUrl, ?string $caption = null): array
            {
                $this->mediaCalls++;
                $this->lastMediaUrl = $mediaUrl;
                $this->lastMediaCaption = $caption;

                return ['status' => 'sent', 'provider_message_id' => 'SM_MED_'.$this->mediaCalls, 'response' => null];
            }
        };
        $this->app->instance(WhatsAppService::class, $fake);

        return $fake;
    }

    /**
     * @return array{0: Event, 1: Guest}
     */
    private function seedInvite(string $outboundSid, string $phone = '+260971234567', $rsvpDeadline = null): array
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create([
            'rsvp_deadline' => $rsvpDeadline,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'phone' => $phone,
            'invitation_token' => str_repeat('a', 48),
        ]);
        NotificationLog::query()->create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'channel' => 'whatsapp',
            'type' => 'guest_invitation_whatsapp',
            'status' => NotificationLog::STATUS_SENT,
            'provider_message_id' => $outboundSid,
            'sent_at' => now(),
        ]);

        return [$event, $guest];
    }

    /**
     * @param  array<string, string>  $params
     */
    private function postSignedWebhook(array $params)
    {
        $url = route('webhooks.twilio.whatsapp');
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature($url, $params);

        return $this->post($url, $params, [
            'X-Twilio-Signature' => $signature,
        ]);
    }
}
