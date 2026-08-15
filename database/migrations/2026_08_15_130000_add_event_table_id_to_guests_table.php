<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seating assignment reuses the existing 'event_tables' entity (the QR photo-wall
 * table) rather than inventing a second, unrelated "table number" concept — see
 * plans/guest-entry-pass.md §0. Null on delete: removing a table (or leaving a
 * guest unassigned) must never delete the guest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->foreignId('event_table_id')->nullable()->after('guest_group_id')
                ->constrained('event_tables')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_table_id');
        });
    }
};
