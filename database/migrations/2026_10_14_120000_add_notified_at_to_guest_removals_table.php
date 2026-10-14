<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the host sent the removed guest the one "you were removed" email (plans/guest-deletion-gaps.md Phase 5). Null means
     * not told. It is also what stops a second send: the host's "Tell them" button is gone once this is set.
     */
    public function up(): void
    {
        Schema::table('guest_removals', function (Blueprint $table): void {
            $table->timestamp('notified_at')->nullable()->after('removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('guest_removals', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
