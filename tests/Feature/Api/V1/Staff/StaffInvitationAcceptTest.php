<?php

namespace Tests\Feature\Api\V1\Staff;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice D — /api/v1/staff-invitations/{token}, the one new App Link this slice
 * adds. Mirrors tests/Feature/EventStaffInvitationAcceptanceTest.php's proof
 * shape: a token-issuing accept instead of Auth::login()+redirect.
 */
class StaffInvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_token_404s(): void
    {
        $this->getJson(route('api.v1.staff-invitations.show', 'not-a-real-token'))->assertNotFound();
    }

    public function test_expired_invite_returns_410(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staff = EventStaff::factory()->for($event)->for($owner, 'inviter')->expired()->create();

        $this->getJson(route('api.v1.staff-invitations.show', $staff->invite_token))->assertStatus(410);
    }

    public function test_new_account_accepts_and_returns_a_usable_token(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staff = EventStaff::factory()->for($event)->for($owner, 'inviter')->create(['email' => 'newperson@example.com']);

        $show = $this->getJson(route('api.v1.staff-invitations.show', $staff->invite_token));
        $show->assertOk()->assertJsonPath('email', 'newperson@example.com')->assertJsonPath('account_exists', false);

        $accept = $this->postJson(route('api.v1.staff-invitations.store', $staff->invite_token), [
            'name' => 'New Person',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $accept->assertCreated();
        $token = $accept->json('token');
        $this->assertNotEmpty($token);

        $user = User::query()->where('email', 'newperson@example.com')->firstOrFail();
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);

        $staff->refresh();
        $this->assertSame($user->id, $staff->user_id);
        $this->assertNotNull($staff->accepted_at);

        // The returned token actually authenticates the new user. GET /me returns
        // UserResource unwrapped (no "user" key) — see MeController/UserResource.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.v1.me'))
            ->assertOk()
            ->assertJsonPath('email', 'newperson@example.com');
    }

    public function test_confirm_rejects_a_mismatched_logged_in_account(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staff = EventStaff::factory()->for($event)->for($owner, 'inviter')->create(['email' => 'invited@example.com']);

        $wrongUser = User::factory()->create(['email' => 'someoneelse@example.com']);
        $token = $wrongUser->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.v1.staff-invitations.confirm', $staff->invite_token))
            ->assertForbidden();

        $this->assertNull($staff->refresh()->accepted_at);
    }
}
