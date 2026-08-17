<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 19: ticket refunds are manual / off-platform. Hosts no longer
     * collect refund-policy copy on a ticket type.
     */
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('refund_policy');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->text('refund_policy')->nullable()->after('terms');
        });
    }
};
