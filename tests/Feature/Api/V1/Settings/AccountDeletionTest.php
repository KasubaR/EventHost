<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Enums\TicketOrderStatus;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Slice E — DELETE /api/v1/settings/account. JSON sibling of
 * App\Http\Controllers\Settings\AccountController::destroy() — same
 * paid-ticket-sales block, verbatim.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Doesn't chain a second HTTP call onto the same token afterwards to
     * prove it stops authenticating — Illuminate\Auth\RequestGuard caches
     * its resolved user for the guard instance's lifetime, which Laravel's
     * test container reuses across calls within one test method (same
     * reasoning tests/Feature/Api/V1/Auth/LogoutTest.php already documents).
     * The account and its token being gone is asserted directly instead.
     */
    public function test_owner_can_delete_their_account_and_its_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson(route('api.v1.settings.account.destroy'), ['password' => 'Password123'])
            ->assertOk();

        $this->assertNull(User::query()->find($user->id));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_password_is_rejected_and_account_survives(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson(route('api.v1.settings.account.destroy'), ['password' => 'WrongPassword'])
            ->assertStatus(422);

        $this->assertNotNull($user->fresh());
    }

    public function test_deletion_is_blocked_by_a_paid_ticketed_event(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::Paid]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson(route('api.v1.settings.account.destroy'), ['password' => 'Password123'])
            ->assertStatus(409);

        $this->assertNotNull($user->fresh());
    }
}
