<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Same shape as guests.checked_in_at / checked_in_by. status flips to
            // TicketStatus::Used at the same time these are set — see
            // TicketCheckInService::confirm().
            $table->timestamp('checked_in_at')->nullable()->after('issued_at');
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn('checked_in_at');
        });
    }
};
