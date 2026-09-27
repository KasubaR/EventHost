<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string}>
     */
    public static function legalRoutes(): array
    {
        return [
            'privacy' => ['legal.privacy'],
            'terms' => ['legal.terms'],
            'cookies' => ['legal.cookies'],
        ];
    }

    // ── Phase 3 of plans/event-retention.md: the retention wording follows the setting ──

    public function test_privacy_describes_the_recovery_window_and_the_payment_exemption_once_purging_is_on(): void
    {
        config(['events.retention.deleted_days' => 30]);

        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('stays in')
            ->assertSee('Recently deleted')
            ->assertSee('for 30 days so you can restore it')
            ->assertSee('permanently removed together with its guest list, RSVPs and uploaded photos and media')
            ->assertSee('Deleting your account removes your events')
            ->assertSee('Events with ticket sales or contribution payments')
            ->assertSee('You can still restore such an event')
            // The old promise it replaces must be gone, not left beside the new one.
            ->assertDontSee('until you delete the event, or delete your account');
    }

    public function test_privacy_wording_uses_the_configured_number_of_days(): void
    {
        config(['events.retention.deleted_days' => 45]);

        $this->get(route('legal.privacy'))->assertSee('for 45 days so you can restore it')->assertDontSee('for 30 days');
    }

    public function test_privacy_keeps_its_existing_wording_while_purging_is_off(): void
    {
        config(['events.retention.deleted_days' => 0]);

        // The page must never promise a window the job is not enforcing.
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('until you delete the event, or delete your account')
            ->assertDontSee('so you can restore it')
            ->assertDontSee('Events with ticket sales or contribution payments')
            ->assertSee('Payment records');
    }

    /** @return array<string, array{0: int}> */
    public static function retentionSettings(): array
    {
        return ['purging on' => [30], 'purging off' => [0]];
    }

    // plans/event-retention.md §6b: account deletion behaves this way whatever the purge setting is,
    // so the wording that describes it must not depend on it.
    #[DataProvider('retentionSettings')]
    public function test_privacy_describes_what_happens_to_payments_and_paid_events_on_account_deletion(int $days): void
    {
        config(['events.retention.deleted_days' => $days]);

        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('your own subscription and credit purchases')
            ->assertSee('with the name and email address you paid')
            ->assertSee('We cannot delete an account while one of its events has ticket sales, refunds or')
            ->assertSee('or while a payment is still being processed');
    }

    // plans/guest-email-reminders.md Phase 4: like §7 and the purge, the reminder wording follows what is switched
    // on, so the page never promises a message the platform is not sending.
    public function test_privacy_says_only_what_is_true_about_guest_reminders(): void
    {
        $deadline = 'an email before the RSVP deadline to guests who have not replied';
        $eventEmail = 'an email a week before, the day before and on the day to guests who have accepted';
        $whatsApp = 'a WhatsApp message on that same schedule to guests who have accepted';

        // Deadline reminders are live whatever else is on; both new channels off.
        config(['communications.guest_email_reminders.enabled' => false, 'communications.whatsapp.enabled' => false]);
        $this->get(route('legal.privacy'))->assertOk()
            ->assertSee($deadline)
            ->assertSee('stops further reminder emails to that guest for that event')
            ->assertSee('remind guests about your event and run check-in')
            ->assertDontSee('a week before')
            ->assertDontSee('WhatsApp')
            ->assertDontSee('Twilio');

        config(['communications.guest_email_reminders.enabled' => true]);
        $this->get(route('legal.privacy'))->assertSee($deadline.' and '.$eventEmail)->assertDontSee('WhatsApp')->assertDontSee('Twilio');

        config(['communications.guest_email_reminders.enabled' => false, 'communications.whatsapp.enabled' => true]);
        $this->get(route('legal.privacy'))->assertSee($deadline.' and '.$whatsApp)->assertSee('Twilio')->assertDontSee('a week before');

        config(['communications.guest_email_reminders.enabled' => true]);
        $this->get(route('legal.privacy'))
            ->assertSee($deadline.', '.$eventEmail.' and '.$whatsApp)
            ->assertSee('Twilio')
            ->assertSee('To send WhatsApp messages about your event');
    }

    public function test_terms_say_payment_records_and_paid_events_are_the_exception_to_deletion(): void
    {
        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertSee('Records of payments are the exception')
            ->assertSee('we cannot delete an account while one of its events has ticket sales, refunds or contribution');
    }

    public function test_the_delete_account_page_warns_about_the_same_exception(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/account')
            ->assertOk()
            ->assertSee('Payment records we are required to keep are the exception');
    }

    #[DataProvider('legalRoutes')]
    public function test_the_policy_pages_are_public(string $route): void
    {
        $this->get(route($route))->assertOk();
    }

    #[DataProvider('legalRoutes')]
    public function test_each_policy_links_to_the_other_two(string $route): void
    {
        $response = $this->get(route($route))->assertOk();

        $response->assertSee(route('legal.privacy'), escape: false);
        $response->assertSee(route('legal.terms'), escape: false);
        $response->assertSee(route('legal.cookies'), escape: false);
    }

    public function test_the_footer_links_to_all_three_policies(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('legal.privacy'), escape: false)
            ->assertSee(route('legal.terms'), escape: false)
            ->assertSee(route('legal.cookies'), escape: false);
    }

    public function test_the_signup_and_signin_consent_lines_are_not_dead_links(): void
    {
        foreach (['register', 'login'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee(route('legal.terms'), escape: false)
                ->assertSee(route('legal.privacy'), escape: false);
        }
    }

    public function test_the_footer_has_no_placeholder_links_left(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $footer = substr($html, (int) strrpos($html, '<footer'));

        $this->assertStringNotContainsString('href="#"', $footer);
    }

    public function test_social_icons_are_hidden_until_a_profile_url_is_configured(): void
    {
        config(['social' => ['x' => null, 'linkedin' => null, 'facebook' => null, 'instagram' => null]]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('social-link', escape: false);
    }

    public function test_a_configured_social_profile_renders_with_a_safe_target(): void
    {
        config(['social.instagram' => 'https://instagram.com/eventhost']);

        $this->get('/')
            ->assertOk()
            ->assertSee('https://instagram.com/eventhost', escape: false)
            ->assertSee('rel="noopener noreferrer"', escape: false);
    }
}
