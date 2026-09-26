<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\RsvpConfirmationNotification;
use App\Services\GuestPassImageService;
use App\Services\GuestPassPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4 of plans/invitation-pass-card.md: the RSVP confirmation email carries the
 * invitation pass (PDF + card image), and WhatsApp carries the card image.
 */
class GuestPassMailTest extends TestCase
{
    use RefreshDatabase;

    private function guestWith(RsvpStatus $status, ?User $owner = null, string $token = 'mail-pass-token'): array
    {
        $event = Event::factory()->for($owner ?? User::factory()->pro()->create())->create([
            'name' => 'Amy and Joe Wedding',
            'event_date' => now()->addDays(20)->startOfDay(),
        ]);
        $guest = Guest::factory()->for($event)->create(['name' => 'Chanda Mwila', 'invitation_token' => $token]);
        $rsvp = Rsvp::factory()->for($guest)->create(['status' => $status, 'attendee_count' => 2]);

        return [$event, $guest, $rsvp];
    }

    /** @return array<string, array{name: string, data: string, options: array<string, mixed>}> keyed by file name */
    private function attachments(RsvpConfirmationNotification $notification): array
    {
        $message = $notification->toMail(new \stdClass);

        return collect($message->rawAttachments)->keyBy('name')->all();
    }

    public function test_an_eligible_guest_gets_the_pdf_and_the_card_image(): void
    {
        Storage::fake('local');
        [$event, $guest, $rsvp] = $this->guestWith(RsvpStatus::Accepted);

        $files = $this->attachments(new RsvpConfirmationNotification($event, $guest, $rsvp));

        $this->assertSame(['amy-and-joe-wedding-pass.pdf', 'amy-and-joe-wedding-pass.png'], array_keys($files));
        $this->assertStringStartsWith('%PDF', $files['amy-and-joe-wedding-pass.pdf']['data']);
        $this->assertSame('application/pdf', $files['amy-and-joe-wedding-pass.pdf']['options']['mime']);

        $info = getimagesizefromstring($files['amy-and-joe-wedding-pass.png']['data']);
        $this->assertNotFalse($info);
        $this->assertGreaterThan($info[0], $info[1], 'The attachment is the full portrait card, not the square QR.');
    }

    public function test_the_mail_points_at_the_pass_page_and_keeps_the_change_rsvp_link(): void
    {
        Storage::fake('local');
        [$event, $guest, $rsvp] = $this->guestWith(RsvpStatus::Accepted);

        $message = (new RsvpConfirmationNotification($event, $guest, $rsvp))->toMail(new \stdClass);

        $this->assertSame('View your pass', $message->actionText);
        $this->assertSame(route('rsvp.token.pass', $guest->invitation_token), $message->actionUrl);
        $this->assertStringContainsString(
            route('rsvp.token.show', $guest->invitation_token),
            implode(' ', array_map('strval', $message->outroLines)),
        );
    }

    public function test_a_declined_guest_gets_no_attachments_and_the_old_change_link(): void
    {
        [$event, $guest, $rsvp] = $this->guestWith(RsvpStatus::Declined, token: 'declined-mail-token');

        $notification = new RsvpConfirmationNotification($event, $guest, $rsvp);
        $message = $notification->toMail(new \stdClass);

        $this->assertSame([], $message->rawAttachments);
        $this->assertSame('View or change your RSVP', $message->actionText);
    }

    public function test_a_guest_on_a_basic_plan_gets_no_attachments(): void
    {
        [$event, $guest, $rsvp] = $this->guestWith(RsvpStatus::Accepted, User::factory()->create(), 'basic-mail-token');

        $this->assertSame([], (new RsvpConfirmationNotification($event, $guest, $rsvp))->toMail(new \stdClass)->rawAttachments);
    }

    public function test_when_the_image_cannot_be_drawn_the_pdf_still_goes_out(): void
    {
        Storage::fake('local');
        [$event, $guest, $rsvp] = $this->guestWith(RsvpStatus::Accepted, token: 'font-mail-token');

        $font = resource_path('fonts/DejaVuSans-Bold.ttf');
        $this->assertTrue(rename($font, $font.'.hidden'));

        try {
            $files = $this->attachments(new RsvpConfirmationNotification($event, $guest, $rsvp));
        } finally {
            rename($font.'.hidden', $font);
        }

        $this->assertSame(['amy-and-joe-wedding-pass.pdf'], array_keys($files));
    }

    public function test_the_mail_falls_back_to_the_bare_qr_only_when_neither_renderer_works(): void
    {
        Storage::fake('local');
        [$event, $guest, $rsvp] = $this->guestWith(RsvpStatus::Accepted, token: 'nothing-mail-token');

        // Neither renderer can run without the card data; force both to fail by breaking the card.
        $this->mock(GuestPassPdfService::class)->shouldReceive('render')->andThrow(new \RuntimeException('pdf down'));
        $this->mock(GuestPassImageService::class)->shouldReceive('render')->andThrow(new \RuntimeException('image down'));

        $files = $this->attachments(new RsvpConfirmationNotification($event, $guest, $rsvp));

        $this->assertSame(['chanda-mwila-entry-pass.png'], array_keys($files));
        $info = getimagesizefromstring($files['chanda-mwila-entry-pass.png']['data']);
        $this->assertSame($info[0], $info[1], 'Fallback is the square QR the mail always carried.');
    }
}
