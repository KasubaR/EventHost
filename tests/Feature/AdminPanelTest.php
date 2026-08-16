<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Report;
use App\Models\User;
use App\Support\TicketingSettings;
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

    public function test_admin_first_publish_of_an_unpaid_draft_spends_the_owner_credit(): void
    {
        $operator = Admin::factory()->create();
        $operator->assignRole('admin');

        $owner = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($owner)->create(['is_published' => false]);

        $this->actingAs($operator, 'admin')
            ->patch(route('admin.events.publish', $event), ['is_published' => true])
            ->assertRedirect()
            ->assertSessionHas('status', 'event-publish-updated');

        $this->assertTrue((bool) $event->fresh()->is_published);
        $this->assertSame(0, $owner->fresh()->event_credits);
        $this->assertSame(
            CreditTransaction::REASON_EVENT_PUBLISHED,
            CreditTransaction::query()->where('event_id', $event->id)->value('reason')
        );
    }

    public function test_admin_cannot_publish_an_unpaid_draft_when_the_owner_has_no_credits(): void
    {
        $operator = Admin::factory()->create();
        $operator->assignRole('admin');

        $owner = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($owner)->create(['is_published' => false]);

        $this->actingAs($operator, 'admin')
            ->patch(route('admin.events.publish', $event), ['is_published' => true])
            ->assertRedirect()
            ->assertSessionHasErrors('is_published');

        $this->assertFalse((bool) $event->fresh()->is_published);
        $this->assertSame(0, $owner->fresh()->event_credits);
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
                'ticketing_commission_percent' => '5.00',
                'ticketing_cancellation_fee_percent' => '0.00',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('Ops branded title', PlatformSetting::getValue('site_name'));
        $this->assertSame('Hello world', PlatformSetting::getValue('whatsapp_default_message'));
        $this->assertSame('5.00', PlatformSetting::getValue('ticketing_commission_percent'));
        $this->assertSame('0.00', PlatformSetting::getValue('ticketing_cancellation_fee_percent'));
    }

    public function test_admin_can_update_ticketing_commission(): void
    {
        $operator = Admin::factory()->create();
        $operator->assignRole('admin');

        $this->actingAs($operator, 'admin')
            ->patch(route('admin.settings.update'), [
                'site_name' => config('app.name'),
                'whatsapp_default_message' => '',
                'ticketing_commission_percent' => '7.5',
                'ticketing_cancellation_fee_percent' => '10',
            ])
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('7.50', TicketingSettings::commissionPercent());
        $this->assertSame('10.00', TicketingSettings::cancellationFeePercent());
    }
}
