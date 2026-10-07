<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which gateway took a payment: `lenco` (mobile money, bank transfer — every existing row)
 * or `astragate` (card). The `lenco_*` columns are reused as the generic provider fields
 * for Astragate rows; renaming them is a much larger change and is deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'ticket_payments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('gateway', 20)->default('lenco')->index();
                $table->string('checkout_session_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'ticket_payments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropIndex(['gateway']);
                $table->dropColumn(['gateway', 'checkout_session_id']);
            });
        }
    }
};
