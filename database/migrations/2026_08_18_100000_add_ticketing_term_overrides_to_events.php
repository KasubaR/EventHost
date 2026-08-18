<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('commission_percent_override', 5, 2)->nullable()->after('agreed_payout_on');
            $table->decimal('cancellation_fee_percent_override', 5, 2)->nullable()->after('commission_percent_override');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['commission_percent_override', 'cancellation_fee_percent_override']);
        });
    }
};
