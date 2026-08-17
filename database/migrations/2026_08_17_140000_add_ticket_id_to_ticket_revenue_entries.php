<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional per-ticket link. Sale rows leave this null (one row per
     * order). Unique (ticket_id, type) is for a later per-ticket writer
     * if one is added; NULLs are distinct so sale rows never collide.
     * Buyer refunds are off-platform and do not post ledger rows.
     */
    public function up(): void
    {
        Schema::table('ticket_revenue_entries', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('ticket_order_id')
                ->constrained()->nullOnDelete();

            $table->unique(['ticket_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_revenue_entries', function (Blueprint $table) {
            // FK first: MySQL refuses to drop an index a foreign key still
            // depends on (errno 1553), so dropUnique() before dropForeign()
            // fails outright.
            $table->dropForeign(['ticket_id']);
            $table->dropUnique(['ticket_id', 'type']);
            $table->dropColumn('ticket_id');
        });
    }
};
