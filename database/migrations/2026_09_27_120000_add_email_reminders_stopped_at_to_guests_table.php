<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest can switch off reminder emails for the event they were invited to from a link in the email
     * itself (plans/guest-email-reminders.md Phase 3). Guests have no account, so the choice lives on
     * their guest row; the timestamp says when they did it. It covers both reminder emails — the RSVP
     * deadline reminder and the event reminder — and nothing else.
     */
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('email_reminders_stopped_at')->nullable()->after('whatsapp_event_reminders_sent');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('email_reminders_stopped_at');
        });
    }
};
