<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The invite form has always required a name (StoreEventStaffRequest)
        // but the column to store it never shipped — the value was validated
        // and silently dropped. Nullable because rows created before this
        // migration have none; the staff list already falls back to email
        // for display in that case.
        Schema::table('event_staff', function (Blueprint $table) {
            $table->string('name')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('event_staff', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
