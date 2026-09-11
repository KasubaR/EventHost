<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Paid add-on (K250, any plan) — hides the EventHost bar from
            // this event's public pages once true. See plans/remove-branding.md.
            // Set only by PaymentCompletionService::complete() on a completed
            // remove_branding payment; never toggled directly by a host.
            $table->boolean('branding_removed')->default(false)->after('contribution_amount');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('branding_removed');
        });
    }
};
