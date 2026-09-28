<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\EnterpriseQuoteRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseQuoteRequestAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function adminWithManageStatus(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function supportAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('support');

        return $admin;
    }

    public function test_admin_sees_the_pending_request_on_the_user_page(): void
    {
        $user = User::factory()->create();
        EnterpriseQuoteRequest::query()->create(['user_id' => $user->id, 'message' => 'Please call me']);

        $this->actingAs($this->adminWithManageStatus(), 'admin')
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('Enterprise quote request', false)
            ->assertSee('Please call me', false);
    }

    public function test_admin_can_dismiss_a_pending_request(): void
    {
        $user = User::factory()->create();
        $request = EnterpriseQuoteRequest::query()->create(['user_id' => $user->id]);

        $this->actingAs($this->adminWithManageStatus(), 'admin')
            ->delete(route('admin.users.enterprise-request.dismiss', [$user, $request]))
            ->assertRedirect();

        $this->assertNotNull($request->fresh()->dismissed_at);
    }

    public function test_dismissing_an_already_dismissed_request_is_rejected(): void
    {
        $user = User::factory()->create();
        $request = EnterpriseQuoteRequest::query()->create(['user_id' => $user->id]);
        $request->forceFill(['dismissed_at' => now()])->save();

        $this->actingAs($this->adminWithManageStatus(), 'admin')
            ->delete(route('admin.users.enterprise-request.dismiss', [$user, $request]))
            ->assertSessionHasErrors('enterprise_request');
    }

    public function test_support_cannot_dismiss_a_request(): void
    {
        $user = User::factory()->create();
        $request = EnterpriseQuoteRequest::query()->create(['user_id' => $user->id]);

        $this->actingAs($this->supportAdmin(), 'admin')
            ->delete(route('admin.users.enterprise-request.dismiss', [$user, $request]))
            ->assertForbidden();

        $this->assertNull($request->fresh()->dismissed_at);
    }

    public function test_a_request_belonging_to_a_different_user_404s(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $request = EnterpriseQuoteRequest::query()->create(['user_id' => $otherUser->id]);

        $this->actingAs($this->adminWithManageStatus(), 'admin')
            ->delete(route('admin.users.enterprise-request.dismiss', [$user, $request]))
            ->assertNotFound();
    }
}
