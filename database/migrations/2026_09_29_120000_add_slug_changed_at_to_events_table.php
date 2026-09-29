<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Set once, by EventSlugService::apply(), the first time a host
            // changes the custom URL after the event already exists. A
            // non-null value locks the field — the custom URL may be
            // changed exactly once. The auto-generated/initial slug set at
            // creation never sets this.
            $table->timestamp('slug_changed_at')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('slug_changed_at');
        });
    }
};
