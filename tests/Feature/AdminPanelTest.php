<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Event;
use App\Models\Payment;
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

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_guest_cannot_access_admin_dashboard(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_regular_user_is_redirected_from_admin_area_without_admin_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_dashboard_revenue_counts_only_completed_payments_in_billing_currency(): void
    {
        Payment::factory()->completed()->create(['amount' => 450.00]);
        Payment::factory()->completed()->create(['amount' => 50.50]);
        Payment::factory()->create(['amount' => 999.00]);                       // pending — excluded
        Payment::factory()->completed()->create([
            'amount' => 700.00,
            'currency' => 'USD',                                                // other currency — excluded
        ]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Total revenue (ZMW)')
            ->assertSee('500.50')
            ->assertDontSee('2,199.50');
    }

    public function test_super_admin_can_view_dashboard_and_analytics(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Platform overview');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Platform analytics');
    }

    public function test_super_admin_can_suspend_another_user(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['status' => 'active']);

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.users.status', $target), ['status' => 'suspended'])
            ->assertSessionHasNoErrors();

        $this->assertSame('suspended', $target->fresh()->status);
    }

    public function test_super_admin_cannot_suspend_self_via_admin_form(): void
    {
        $customer = User::factory()->create(['status' => 'active']);
        $admin = Admin::factory()->create([
            'user_id' => $customer->id,
            'email' => $customer->email,
        ]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.users.show', $customer))
            ->patch(route('admin.users.status', $customer), ['status' => 'suspended'])
            ->assertRedirect(route('admin.users.show', $customer))
            ->assertSessionHasErrors(['status']);

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_super_admin_can_delete_another_user(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_operation_admin_cannot_delete_users_without_permission(): void
    {
        $operator = Admin::factory()->create();
        $operator->assignRole('admin');

        $target = User::factory()->create();

        $this->actingAs($operator, 'admin')
            ->delete(route('admin.users.destroy', $target))
            ->assertForbidden();
    }

    public function test_admin_can_toggle_event_publish_state(): void
    {
        $operator = Admin::factory()->create();
        $operator->assignRole('admin');

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create();

        $this->actingAs($operator, 'admin')
            ->patch(route('admin.events.publish', $event), ['is_published' => false])
            ->assertRedirect();

        $this->assertFalse($event->fresh()->is_published);
    }

    public function test_support_user_can_resolve_reports_but_not_manage_settings(): void
    {
        $support = Admin::factory()->create();
        $support->assignRole('support');

        $report = Report::factory()->create(['status' => Report::STATUS_PENDING]);

        $this->actingAs($support, 'admin')
            ->patch(route('admin.reports.update', $report), ['status' => Report::STATUS_RESOLVED])
            ->assertRedirect();

        $this->assertSame(Report::STATUS_RESOLVED, $report->fresh()->status);

        $this->actingAs($support, 'admin')
            ->get(route('admin.settings.edit'))
            ->assertForbidden();
    }

    public function test_admin_can_update_platform_settings(): void
    {
        $operator = Admin::factory()->create();
        $operator->assignRole('admin');

        $this->actingAs($operator, 'admin')
            ->patch(route('admin.settings.update'), [
                'site_name' => 'Ops branded title',
                'whatsapp_default_message' => 'Hello world',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('Ops branded title', PlatformSetting::getValue('site_name'));
        $this->assertSame('Hello world', PlatformSetting::getValue('whatsapp_default_message'));
    }
}
