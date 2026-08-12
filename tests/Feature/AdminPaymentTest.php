<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_cannot_access_admin_payments(): void
    {
        $this->get(route('admin.payments.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_super_admin_can_view_payments_index(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        $user = User::factory()->withoutCredits()->create();
        Payment::factory()->for($user)->create([
            'payment_reference' => 'EH-admin-list',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('EH-admin-list', escape: false);
    }

    public function test_super_admin_can_view_payment_detail(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        $payment = Payment::factory()->for(User::factory()->withoutCredits())->create([
            'payment_reference' => 'EH-admin-show',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.show', $payment))
            ->assertOk()
            ->assertSee('EH-admin-show', escape: false);
    }
}
