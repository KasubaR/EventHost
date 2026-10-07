<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\GuestLinkToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-token-edge-cases.md Phase 2 (a pasted link is cleaned, a dead one explains itself) and
 * Phase 4 (guessing tokens is throttled per IP without touching the pass images).
 */
class RsvpLinkRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'AbCdEf0123456789AbCdEf0123456789AbCdEf0123456789';

    private function guest(string $token = self::TOKEN): Guest
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create(['rsvp_deadline' => null, 'guest_limit' => null]);

        return Guest::factory()->for($event)->create(['invitation_token' => $token, 'email' => 'g@example.test']);
    }

    public function test_clean_strips_what_clings_to_a_pasted_link_and_rejects_what_cannot_be_a_token(): void
    {
        $this->assertSame(self::TOKEN, GuestLinkToken::clean(self::TOKEN));
        $this->assertSame(self::TOKEN, GuestLinkToken::clean(self::TOKEN.')'));
        $this->assertSame(self::TOKEN, GuestLinkToken::clean(self::TOKEN.'.'));
        $this->assertSame(self::TOKEN, GuestLinkToken::clean(' '.self::TOKEN.' '));
        $this->assertSame(self::TOKEN, GuestLinkToken::clean('<'.self::TOKEN.'>.'));
        $this->assertSame(self::TOKEN, GuestLinkToken::clean('"'.self::TOKEN.'",'));

        // Case is never touched, and a legitimate - or _ inside is kept.
        $this->assertSame('Ab_c-D', GuestLinkToken::clean('Ab_c-D'));

        $this->assertNull(GuestLinkToken::clean(''));
        $this->assertNull(GuestLinkToken::clean('...'));
        $this->assertNull(GuestLinkToken::clean(str_repeat('a', 65)));
        $this->assertNull(GuestLinkToken::clean('abc/def'));
        $this->assertNull(GuestLinkToken::clean('ab cd'));
        $this->assertNull(GuestLinkToken::clean('a@b'));
    }

    public function test_a_pasted_link_with_trailing_junk_redirects_to_the_real_one(): void
    {
        $this->guest();

        foreach ([')', '.', '%20', '%29.', '>'] as $junk) {
            $this->get('/rsvp/'.self::TOKEN.$junk)
                ->assertRedirect(route('rsvp.token.show', ['token' => self::TOKEN]));
        }

        $this->get('/rsvp/'.self::TOKEN.')/thanks')->assertRedirect(route('rsvp.token.thanks', ['token' => self::TOKEN]));
        $this->get(route('rsvp.token.show', ['token' => self::TOKEN]))->assertOk();
    }

    public function test_a_damaged_link_for_a_guest_that_does_not_exist_is_the_friendly_not_found(): void
    {
        $this->get('/rsvp/'.str_repeat('z', 48).').')
            ->assertNotFound()
            ->assertSee("We couldn't find this invitation", false)
            ->assertSee('Ask the person who invited you', false);
    }

    public function test_a_post_to_a_damaged_link_saves_the_answer_without_a_redirect_that_would_drop_the_form(): void
    {
        Notification::fake();
        $guest = $this->guest();

        $this->post('/rsvp/'.self::TOKEN.').', ['status' => 'accepted', 'attendee_count' => 1])
            ->assertRedirect(route('rsvp.token.thanks', ['token' => self::TOKEN]));

        $this->assertSame(1, Rsvp::query()->where('guest_id', $guest->id)->count());
    }

    public function test_a_group_link_is_cleaned_too(): void
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create(['rsvp_deadline' => null]);
        $group = GuestGroup::factory()->for($event)->create();
        $group->enableLink(3);
        $group = $group->fresh();

        $this->get('/g/'.$group->rsvp_token.').')->assertRedirect(route('group-rsvp.show', ['token' => $group->rsvp_token]));
        $this->get('/g/'.str_repeat('q', 48))->assertNotFound()->assertSee("We couldn't find this invitation", false);
    }

    public function test_a_value_that_cannot_be_a_token_is_a_404_without_a_guest_lookup(): void
    {
        DB::enableQueryLog();

        $this->get('/rsvp/'.str_repeat('a', 200))->assertNotFound();
        $this->get('/rsvp/a@b')->assertNotFound();

        $guestQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'invitation_token'));
        $this->assertCount(0, $guestQueries, 'an impossible token must not reach the database');
    }

    public function test_the_api_and_the_image_routes_keep_a_plain_404(): void
    {
        $this->getJson('/api/v1/rsvp/'.str_repeat('z', 48))->assertNotFound();
        $this->get('/rsvp/'.str_repeat('z', 48).'/pass.png')->assertNotFound()->assertDontSee('this invitation', false);

        // The API cleans a pasted token but never redirects.
        $this->guest();
        $this->getJson('/api/v1/rsvp/'.self::TOKEN.')')->assertOk();
    }

    public function test_the_old_link_after_a_regenerate_gets_the_not_found_page(): void
    {
        $guest = $this->guest();
        $guest->update(['invitation_token' => 'NewTokenNewTokenNewTokenNewTokenNewTokenNew1']);

        $this->get(route('rsvp.token.show', ['token' => self::TOKEN]))->assertNotFound()->assertSee("We couldn't find this invitation", false);
    }

    // ---------------------------------------------------------------- Phase 4

    public function test_posting_endless_wrong_tokens_from_one_ip_is_throttled_but_a_normal_guest_is_not(): void
    {
        $payload = ['status' => 'accepted', 'attendee_count' => 1];

        for ($i = 0; $i < 120; $i++) {
            $this->post('/rsvp/'.str_pad((string) $i, 48, 'x'), $payload)->assertNotFound();
        }

        $this->post('/rsvp/'.str_pad('999', 48, 'x'), $payload)->assertStatus(429);

        // A different IP is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/rsvp/'.str_pad('1', 48, 'y'), $payload)
            ->assertNotFound();
    }

    public function test_guest_link_pages_are_throttled_per_ip_but_the_pass_images_are_not(): void
    {
        $guest = $this->guest();

        for ($i = 0; $i < 120; $i++) {
            $this->get(route('rsvp.token.show', ['token' => $guest->invitation_token]))->assertOk();
        }

        $this->get(route('rsvp.token.show', ['token' => $guest->invitation_token]))->assertStatus(429);
        $this->get(route('group-rsvp.show', ['token' => str_repeat('g', 48)]))->assertStatus(429);

        // Twilio and the page's own <img> keep working while the page lookups are limited.
        for ($i = 0; $i < 5; $i++) {
            $this->assertNotSame(429, $this->get(route('rsvp.token.pass-image', ['token' => $guest->invitation_token]))->status());
            $this->assertNotSame(429, $this->get(route('rsvp.token.entry-pass-png', ['token' => $guest->invitation_token]))->status());
            $this->assertNotSame(429, $this->get(route('rsvp.token.entry-pass', ['token' => $guest->invitation_token]))->status());
        }
    }
}
