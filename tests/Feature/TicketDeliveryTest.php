<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use App\Notifications\TicketOrderConfirmationNotification;
use App\Services\QrCodeService;
use App\Services\TicketPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrderWithTicket(array $eventOverrides = [], array $ticketOverrides = []): array
    {
        $event = Event::factory()->ticketed()->create(array_merge([
            'event_date' => '2026-09-20',
            'event_time' => '18:00:00',
            'venue' => 'Lusaka',
        ], $eventOverrides));
        $type = TicketType::factory()->for($event)->create(['name' => 'VIP', 'badge_color' => 'vip']);
        $order = TicketOrder::factory()->for($event)->paid()->create();
        $ticket = Ticket::factory()->create(array_merge([
            'event_id' => $event->id,
            'ticket_order_id' => $order->id,
            'ticket_type_id' => $type->id,
            'attendee_name' => 'John Banda',
        ], $ticketOverrides));

        return [$event, $order, $ticket];
    }

    public function test_order_status_page_shows_payment_successful_with_view_and_download_actions(): void
    {
        [, $order, $ticket] = $this->paidOrderWithTicket();

        $response = $this->get(route('ticket.orders.show', $order->order_reference));

        $response->assertOk();
        $response->assertSee('Payment successful');
        $response->assertSee(route('tickets.show', $ticket->public_token), escape: false);
        $response->assertSee(route('tickets.download', $ticket->public_token), escape: false);
    }

    public function test_ticket_page_offers_a_download_button(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket();

        $this->get(route('tickets.show', $ticket->public_token))
            ->assertOk()
            ->assertSee(route('tickets.download', $ticket->public_token), escape: false)
            ->assertSee('Download ticket');
    }

    public function test_ticket_page_leaves_a_valid_ticket_unmarked(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket([], [
            'status' => TicketStatus::Valid,
            'checked_in_at' => null,
        ]);

        $this->get(route('tickets.show', $ticket->public_token))
            ->assertOk()
            ->assertSee('Show this QR code at the door')
            ->assertDontSee('tkc-qr-frame--used', escape: false)
            ->assertDontSee('tkc-qr-frame--void', escape: false)
            ->assertDontSee('tkc-qr-badge', escape: false)
            ->assertDontSee('Checked in');
    }

    /**
     * The status has to be visible right at the code, not only in the details
     * list below it — a screenshot cropped to the QR was otherwise
     * indistinguishable from an unused ticket. The code itself stays rendered
     * and scannable: re-presenting a used ticket is a normal door event, and
     * the scanner answering "already checked in" is what catches a shared copy.
     */
    public function test_ticket_page_marks_the_qr_once_the_ticket_has_been_checked_in(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket([], [
            'status' => TicketStatus::Used,
            'checked_in_at' => Carbon::parse('2026-09-20 19:42:00'),
        ]);

        $this->get(route('tickets.show', $ticket->public_token))
            ->assertOk()
            ->assertSee('tkc-qr-frame--used', escape: false)
            ->assertSee('tkc-qr-badge--used', escape: false)
            ->assertSee('Checked in 20 Sep, 7:42 PM')
            ->assertSee('already been used for entry')
            ->assertSee(route('tickets.qr', $ticket->public_token), escape: false)
            ->assertDontSee('Show this QR code at the door');
    }

    public function test_ticket_page_marks_a_cancelled_ticket_as_not_valid_for_entry(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket([], ['status' => TicketStatus::Cancelled]);

        $this->get(route('tickets.show', $ticket->public_token))
            ->assertOk()
            ->assertSee('tkc-qr-frame--void', escape: false)
            ->assertSee('tkc-qr-badge--void', escape: false)
            ->assertSee('Cancelled — not valid for entry', escape: false)
            ->assertSee('no longer valid for entry');
    }

    public function test_ticket_page_offers_a_whatsapp_link_when_attendee_has_a_phone(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket([], ['attendee_phone' => '0961234567']);

        $this->get(route('tickets.show', $ticket->public_token))
            ->assertOk()
            ->assertSee('https://wa.me/', escape: false)
            ->assertSee('Send to WhatsApp');
    }

    public function test_ticket_page_omits_the_whatsapp_link_without_a_phone(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket([], ['attendee_phone' => null]);

        $this->get(route('tickets.show', $ticket->public_token))
            ->assertOk()
            ->assertDontSee('Send to WhatsApp');
    }

    public function test_whatsapp_message_contains_the_expected_fields(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket([], ['attendee_phone' => '0961234567']);

        $response = $this->get(route('tickets.show', $ticket->public_token));
        $body = $response->getContent();

        $this->assertStringContainsString(rawurlencode('Ticket: VIP'), $body);
        $this->assertStringContainsString(rawurlencode('Name: John Banda'), $body);
        $this->assertStringContainsString(rawurlencode('Date: 20 September'), $body);
        $this->assertStringContainsString(rawurlencode('Venue: Lusaka'), $body);
    }

    public function test_download_returns_a_pdf(): void
    {
        [, , $ticket] = $this->paidOrderWithTicket();

        $response = $this->get(route('tickets.download', $ticket->public_token));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="ticket-'.$ticket->id.'.pdf"');
    }

    public function test_ticket_pdf_blade_includes_ticket_framing_and_event_details(): void
    {
        [, $order, $ticket] = $this->paidOrderWithTicket();
        $ticket->loadMissing(['event', 'ticketType', 'order']);

        $html = view('tickets.pdf', [
            'ticket' => $ticket,
            'qrDataUri' => 'data:image/png;base64,abc',
            'logoDataUri' => 'data:image/png;base64,logo',
        ])->render();

        $this->assertStringContainsString('class="ticket"', $html);
        $this->assertStringContainsString('type-badge--vip', $html);
        $this->assertStringContainsString($ticket->event->name, $html);
        $this->assertStringContainsString('John Banda', $html);
        $this->assertStringContainsString('Lusaka', $html);
        $this->assertStringContainsString($order->order_reference, $html);
        $this->assertStringContainsString('VIP', $html);
        $this->assertStringContainsString('class="stub"', $html);
        $this->assertStringContainsString('class="perforation"', $html);
        $this->assertStringNotContainsString('—', $html);
    }

    /**
     * The "Used" badge is laid over the modules, so it is only safe while the
     * ticket QR carries ~30% error correction. A denser code for the same
     * payload is the signature of the higher level.
     */
    public function test_ticket_qr_is_rendered_at_high_error_correction(): void
    {
        $service = app(QrCodeService::class);
        $url = 'https://example.test/t/'.str_repeat('a', 48);

        $standard = $service->svg($url);
        $high = $service->svg($url, ecLevel: QrCodeService::ECC_HIGH);

        // The whole code renders as one <path>, so its size stands in for the
        // module count — a higher level needs more modules for the same payload.
        $this->assertNotSame($standard, $high);
        $this->assertGreaterThan(strlen($standard), strlen($high));
    }

    public function test_ticket_qr_route_serves_the_high_error_correction_code(): void
    {
        Cache::flush();

        [, , $ticket] = $this->paidOrderWithTicket();

        $served = $this->get(route('tickets.qr', $ticket->public_token))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            app(QrCodeService::class)->svg($ticket->publicUrl(), ecLevel: QrCodeService::ECC_HIGH),
            $served
        );
        $this->assertNotNull(Cache::get($ticket->qrCacheKey()));
    }

    public function test_ticket_pdf_service_caches_under_v3_path(): void
    {
        Storage::fake('local');

        [, , $ticket] = $this->paidOrderWithTicket();
        $service = app(TicketPdfService::class);

        $this->assertSame(
            'ticket-pdfs/v3/'.$ticket->public_token.'.pdf',
            $service->cachePath($ticket)
        );

        $binary = $service->render($ticket);

        $this->assertNotSame('', $binary);
        $this->assertTrue(Storage::disk('local')->exists($service->cachePath($ticket)));
        $this->assertSame($binary, $service->render($ticket));
    }

    public function test_download_is_throttled_per_ip(): void
    {
        $unknown = str_repeat('a', 48);

        for ($i = 0; $i < 10; $i++) {
            $this->get(route('tickets.download', $unknown))->assertNotFound();
        }

        $this->get(route('tickets.download', $unknown))->assertStatus(429);
    }

    public function test_confirmation_email_includes_order_reference_and_event_information(): void
    {
        [, $order] = $this->paidOrderWithTicket();
        $order->loadMissing(['event', 'tickets.ticketType']);

        $mail = (new TicketOrderConfirmationNotification($order))->toMail($order);
        $lines = collect($mail->introLines);

        $this->assertTrue($lines->contains(fn ($line) => str_contains($line, $order->order_reference)));
        $this->assertTrue($lines->contains(fn ($line) => str_contains($line, 'Lusaka')));
        $this->assertTrue($lines->contains(fn ($line) => str_contains($line, 'September')));
    }

    public function test_confirmation_email_attaches_ticket_pdfs_not_bare_qr_pngs(): void
    {
        [, $order, $ticket] = $this->paidOrderWithTicket();
        $order->loadMissing(['event', 'tickets.ticketType']);

        $mail = (new TicketOrderConfirmationNotification($order))->toMail($order);

        $this->assertCount(1, $mail->rawAttachments);
        $attachment = $mail->rawAttachments[0];

        $this->assertSame(
            'ticket-'.$order->order_reference.'-'.$ticket->id.'.pdf',
            $attachment['name']
        );
        $this->assertSame('application/pdf', $attachment['options']['mime']);
        $this->assertStringStartsWith('%PDF', $attachment['data']);
        $this->assertTrue(
            collect($mail->outroLines)->contains(
                fn ($line) => str_contains($line, 'ticket PDFs are attached')
            )
        );
    }
}
