<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who scanned, when the scanner had no login. checked_in_by only covers
     * dashboard scans — a staff-link scan has no user to point at, so it left
     * "checked in by" blank and an investigation had nothing to go on.
     *
     * A snapshot string rather than a foreign key to event_staff_links:
     * EventStaffLinkController::destroy() hard-deletes the row, so revoking a
     * leaked link — exactly when a host would start investigating — would take
     * the attribution with it. Same reasoning as reviews.author_name.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('checked_in_via_label', 120)->nullable()->after('checked_in_by');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->string('checked_in_via_label', 120)->nullable()->after('checked_in_by');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('checked_in_via_label');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('checked_in_via_label');
        });
    }
};
