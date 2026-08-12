<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('rsvp_reminders_sent');
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn('checked_in_at');
        });
    }
};
