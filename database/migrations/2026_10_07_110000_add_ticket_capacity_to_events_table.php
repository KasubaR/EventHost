<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('events', 'ticket_capacity')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            // Total tickets the venue can take across every ticket type. Null on
            // events that predate the field and on events made through the API,
            // which keeps its contract additive.
            $table->unsignedInteger('ticket_capacity')->nullable()->after('guest_limit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('events', 'ticket_capacity')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('ticket_capacity');
        });
    }
};
