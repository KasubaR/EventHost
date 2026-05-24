<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('event_credits')->default(0)->after('subscription_tier');
        });

        // Give existing users credits equal to their current event count
        // so they are not immediately locked out.
        DB::statement('UPDATE users SET event_credits = (SELECT COUNT(*) FROM events WHERE events.user_id = users.id)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('event_credits');
        });
    }
};
