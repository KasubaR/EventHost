<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\PlatformSetting;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_guest_cannot_access_admin_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_regular_user_is_forbidden_from_admin_area(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_super_admin_can_view_dashboard_and_analytics(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Platform overview');

        $this->actingAs($admin)
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Platform analytics');
    }

    public function test_super_admin_can_suspend_another_user(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $target), ['status' => 'suspended'])
            ->assertSessionHasNoErrors();

        $this->assertSame('suspended', $target->fresh()->status);
    }

    public function test_super_admin_cannot_suspend_self_via_admin_form(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->from(route('admin.users.show', $admin))
            ->patch(route('admin.users.status', $admin), ['status' => 'suspended'])
            ->assertRedirect(route('admin.users.show', $admin))
            ->assertSessionHasErrors(['status']);

        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_super_admin_can_delete_another_user(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_operation_admin_cannot_delete_users_without_permission(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('admin');

        $target = User::factory()->create();

        $this->actingAs($operator)
            ->delete(route('admin.users.destroy', $target))
            ->assertForbidden();
    }

    public function test_admin_can_toggle_event_publish_state(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('admin');

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create();

        $this->actingAs($operator)
            ->patch(route('admin.events.publish', $event), ['is_published' => false])
            ->assertRedirect();

        $this->assertFalse($event->fresh()->is_published);
    }

    public function test_support_user_can_resolve_reports_but_not_manage_settings(): void
    {
        $support = User::factory()->create();
        $support->assignRole('support');

        $report = Report::factory()->create(['status' => Report::STATUS_PENDING]);

        $this->actingAs($support)
            ->patch(route('admin.reports.update', $report), ['status' => Report::STATUS_RESOLVED])
            ->assertRedirect();

        $this->assertSame(Report::STATUS_RESOLVED, $report->fresh()->status);

        $this->actingAs($support)
            ->get(route('admin.settings.edit'))
            ->assertForbidden();
    }

    public function test_admin_can_update_platform_settings(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('admin');

        $this->actingAs($operator)
            ->patch(route('admin.settings.update'), [
                'site_name' => 'Ops branded title',
                'whatsapp_default_message' => 'Hello world',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('Ops branded title', PlatformSetting::getValue('site_name'));
        $this->assertSame('Hello world', PlatformSetting::getValue('whatsapp_default_message'));
    }
}
