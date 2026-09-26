<?php

namespace Tests\Feature;

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
