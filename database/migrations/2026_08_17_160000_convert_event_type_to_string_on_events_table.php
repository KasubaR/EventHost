<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * event_type was a DB-level enum(), hardcoded to the original 7
     * invitation-only values. Event::TICKETED_EVENT_TYPES adds commercial
     * types (concert, conference, ...) that the enum has never heard of, so
     * inserting one throws "Data truncated for column 'event_type'" even
     * though app-level validation (Event::eventTypesFor()) allows it.
     * Widening to a plain string matches product_kind/ticketing_status/
     * commission_mode on this same table, which are already plain strings
     * validated in PHP rather than constrained in the schema — the
     * vocabulary can grow again later without another migration.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('event_type', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->enum('event_type', [
                'wedding',
                'birthday',
                'graduation',
                'corporate',
                'baby_shower',
                'funeral',
                'church',
            ])->change();
        });
    }
};
