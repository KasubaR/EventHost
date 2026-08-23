<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventStaffInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_token_404s(): void
    {
        $this->get(route('staff-invitations.show', 'not-a-real-token'))->assertNotFound();
    }

    public function test_expired_invite_is_rejected(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staff = EventStaff::factory()->for($event)->for($owner, 'inviter')->expired()->create();

        $this->get(route('staff-invitations.show', $staff->invite_token))->assertStatus(410);
    }

    public function test_new_account_can_accept_and_is_logged_in(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staff = EventStaff::factory()->for($event)->for($owner, 'inviter')->create(['email' => 'newperson@example.com']);

        $this->get(route('staff-invitations.show', $staff->invite_token))
            ->assertOk()
            ->assertSee('newperson@example.com');

        $this->post(route('staff-invitations.store', $staff->invite_token), [
            'name' => 'New Person',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect(route('events.show', $event));

        $user = User::query()->where('email', 'newperson@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        // status defaults to 'pending' and is normally flipped to 'active'
        // only by VerifyEmailController alongside email_verified_at — this
        // flow bypasses that controller, so it must set both itself or the
        // account is left verified-but-permanently-pending.
        $this->assertSame('active', $user->status);
        $this->assertAuthenticatedAs($user);

        $staff->refresh();
        $this->assertSame($user->id, $staff->user_id);
        $this->assertNotNull($staff->accepted_at);
        $this->assertNull($staff->invite_token);
    }

    public function test_existing_account_is_redirected_to_confirm_and_login_round_trips(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $existing = User::factory()->create(['email' => 'already@example.com']);
        $staff = EventStaff::factory()->for($event)->for($owner, 'inviter')->create(['email' => 'already@example.com']);

        $this->get(route('staff-invitations.show', $staff->invite_token))
            ->assertRedirect(route('staff-invitations.confirm', $staff->invite_token));

        // Logged out: the auth middleware bounces to login and remembers where to return.
        $this->get(route('staff-invitations.confirm', $staff->invite_token))
            ->assertRedirect(route('login'));

        $this->actingAs($existing)
            ->get(route('staff-invitations.confirm', $staff->invite_token))
            ->assertRedirect(route('events.show', $event));

        $staff->refresh();
        $this->assertSame($existing->id, $staff->user_id);
        $this->assertNotNull($staff->accepted_at);
    }

    public function test_confirm_403s_when_logged_in_as_the_wrong_account(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        User::factory()->create(['email' => 'already@example.com']);
        $wrongUser = User::factory()->create(['email' => 'someone-else@example.com']);
        $staff = EventStaff::factory()->for($event)->for($owner, 'inviter')->create(['email' => 'already@example.com']);

        $this->actingAs($wrongUser)
            ->get(route('staff-invitations.confirm', $staff->invite_token))
            ->assertForbidden();

        $this->assertNull($staff->fresh()->accepted_at);
    }
}
