<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Which organizer portal the event lives in: 'private' | 'public'.
            // See plans/public-private-portals.md. Orthogonal to product_kind.
            $table->string('audience', 16)->default('private')->after('product_kind');
            $table->index('audience');
        });

        // Ticketed events were always public; an invitation event was public
        // only when the host ticked is_public. Raw query on purpose — it must
        // reach soft-deleted rows too, and must not fire model events.
        // Idempotent: re-running just re-asserts the same rule.
        DB::table('events')
            ->where(function ($query): void {
                $query->where('product_kind', 'ticketed')->orWhere('is_public', true);
            })
            ->update(['audience' => 'public']);

        DB::table('events')
            ->where('product_kind', '!=', 'ticketed')
            ->where('is_public', false)
            ->update(['audience' => 'private']);
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['audience']);
            $table->dropColumn('audience');
        });
    }
};
