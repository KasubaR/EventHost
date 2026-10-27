<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional proof of payment (a receipt photo or PDF). Kept on the private disk, never
        // under public/, and only served through the authorised routes that read these columns.
        Schema::table('ticket_payouts', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('note');
            $table->string('proof_original_name', 191)->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_payouts', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_original_name']);
        });
    }
};
