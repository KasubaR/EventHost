<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_templates', function (Blueprint $table) {
            $table->string('min_subscription_tier', 24)->default('base')->after('skin');
            $table->string('layout_variant', 48)->default('standard')->after('min_subscription_tier');
            $table->index(['min_subscription_tier', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('invitation_templates', function (Blueprint $table) {
            $table->dropIndex(['min_subscription_tier', 'is_active']);
            $table->dropColumn(['min_subscription_tier', 'layout_variant']);
        });
    }
};
