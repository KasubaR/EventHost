<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Notifications\ContactMessageNotification;
use App\Notifications\PaymentReceiptNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandedMailThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_welcome_email_html_includes_logo_and_avoids_decorative_hyphens(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);

        $mail = (new WelcomeNotification)->toMail($user);
        $html = $mail->render();

        $this->assertStringContainsString('eventhost-mail.png', $html);
        $this->assertStringNotContainsString('—', $html);
        $this->assertStringNotContainsString('---', $html);
        $this->assertStringContainsString('The '.config('app.name').' Team', $html);
    }

    public function test_payment_receipt_subject_uses_colon_not_em_dash(): void
    {
        $user = User::factory()->create(['event_credits' => 2]);
        $payment = Payment::factory()->for($user)->completed()->create([
            'plan_key' => 'base',
            'credits_granted' => 1,
        ]);

        $mail = (new PaymentReceiptNotification($payment))->toMail($user);

        $this->assertSame('Payment received: '.config('app.name'), $mail->subject);
        $this->assertStringNotContainsString('—', $mail->subject);
    }

    public function test_contact_message_uses_labeled_sections_instead_of_hyphen_bars(): void
    {
        $mail = (new ContactMessageNotification(
            'John Banda',
            'john@example.com',
            'Technical support',
            "First paragraph.\n\nSecond paragraph."
        ))->toMail(new \stdClass);

        $body = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

        $this->assertStringContainsString('**Message**', $body);
        $this->assertStringContainsString('**Reply**', $body);
        $this->assertStringNotContainsString('---', $body);
        $this->assertStringNotContainsString('—', $body);
        $this->assertSame('The '.config('app.name').' Team', $mail->salutation);
    }
}
