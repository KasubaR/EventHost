<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\PlatformSetting;
use App\Support\TicketingSettings;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(InvitationTemplateSeeder::class);
        $this->call(RolePermissionSeeder::class);
        $this->call(FaqSeeder::class);

        $adminEmail = env('ADMIN_EMAIL', 'test@example.com');

        $admin = Admin::query()->firstWhere('email', $adminEmail);

        if ($admin === null) {
            $admin = Admin::factory()->create([
                'name' => 'Super Admin',
                'email' => $adminEmail,
            ]);
        }

        $admin->assignRole('super_admin');

        PlatformSetting::setValue('site_name', config('app.name'), 'string');
        PlatformSetting::setValue('whatsapp_default_message', '', 'string');
        PlatformSetting::setValue(TicketingSettings::KEY_COMMISSION_PERCENT, TicketingSettings::DEFAULT_COMMISSION_PERCENT, 'string');
        PlatformSetting::setValue(TicketingSettings::KEY_CANCELLATION_FEE_PERCENT, TicketingSettings::DEFAULT_CANCELLATION_FEE_PERCENT, 'string');
    }
}
