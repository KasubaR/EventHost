<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What Twilio later reports about a WhatsApp message (plans/whatsapp-sharing-gaps.md item 7). `status = sent` only means
     * Twilio accepted it; `delivery_status` is queued | sent | delivered | read | failed | undelivered from the status
     * callback, and `error_code` is Twilio's code when it failed (63003 = not on WhatsApp). The callback finds its row by
     * `provider_message_id`, hence the index.
     */
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->string('delivery_status', 16)->nullable()->after('status');
            $table->string('error_code', 16)->nullable()->after('delivery_status');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['delivery_status', 'error_code']);
        });
    }
};
