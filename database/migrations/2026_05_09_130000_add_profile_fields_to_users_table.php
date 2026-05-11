<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('company_name')->nullable()->after('phone');
            $table->string('profile_photo')->nullable()->after('company_name');
            $table->json('notification_preferences')->nullable()->after('profile_photo');
            $table->enum('status', ['active', 'suspended', 'pending'])
                ->default('pending')->after('notification_preferences');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            $table->index('phone');
            $table->index('status');
            $table->index('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropIndex(['status']);
            $table->dropIndex(['company_name']);
            $table->dropColumn([
                'phone', 'company_name', 'profile_photo',
                'notification_preferences', 'status',
                'last_login_at', 'last_login_ip',
            ]);
        });
    }
};
