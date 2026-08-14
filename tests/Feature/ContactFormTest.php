<?php

namespace Tests\Feature;

use App\Notifications\ContactMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The contact form used to validate a submission and then discard it — the page
 * reported "Message sent!" while nothing was ever delivered. These cover the
 * delivery path so that cannot regress silently again.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'John Banda',
            'email' => 'john@example.com',
            'subject' => 'Technical support',
            'message' => 'My guests cannot open their invitation links.',
        ], $overrides);
    }

    public function test_contact_page_renders(): void
    {
        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('Contact information');
    }

    public function test_submission_is_delivered_to_the_support_address(): void
    {
        Notification::fake();
        config(['mail.support_address' => 'support@eventhostzm.com']);

        $this->post(route('contact.store'), $this->payload())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        Notification::assertSentOnDemand(
            ContactMessageNotification::class,
            function (ContactMessageNotification $notification, array $channels, object $notifiable): bool {
                return $notifiable->routes['mail'] === 'support@eventhostzm.com';
            }
        );
    }

    public function test_reply_to_is_the_sender_so_support_can_respond(): void
    {
        Notification::fake();

        $this->post(route('contact.store'), $this->payload());

        Notification::assertSentOnDemand(
            ContactMessageNotification::class,
            function (ContactMessageNotification $notification): bool {
                $mail = $notification->toMail(new \stdClass);

                return $mail->replyTo === [['john@example.com', 'John Banda']]
                    && str_contains($mail->subject, 'Technical support')
                    && str_contains(implode(' ', $mail->introLines), 'guests cannot open');
            }
        );
    }

    public function test_invalid_submissions_are_rejected_and_nothing_is_sent(): void
    {
        Notification::fake();

        $this->post(route('contact.store'), $this->payload([
            'email' => 'not-an-email',
            'message' => 'too short',
        ]))->assertSessionHasErrors(['email', 'message']);

        Notification::assertNothingSent();
    }

    public function test_subject_must_be_one_of_the_offered_topics(): void
    {
        Notification::fake();

        $this->post(route('contact.store'), $this->payload([
            'subject' => 'Buy cheap pills now',
        ]))->assertSessionHasErrors('subject');

        Notification::assertNothingSent();
    }
}
