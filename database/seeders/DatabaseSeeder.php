<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\User;
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

        $adminEmail = env('ADMIN_EMAIL', 'test@example.com');

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => $adminEmail,
        ]);

        $user->assignRole('super_admin');

        PlatformSetting::setValue('site_name', config('app.name'), 'string');
        PlatformSetting::setValue('whatsapp_default_message', '', 'string');
    }
}
