<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment is an accounting record the Privacy policy says survives account
     * deletion, but payments.user_id used to cascade — deleting the account deleted
     * the user's own billing history with it (plans/event-retention.md §6b).
     *
     * user_id becomes nullable + nullOnDelete, the same posture ticket_revenue_entries
     * and the payout tables already take. A row that outlives its user would otherwise
     * be anonymous, so payer_name / payer_email hold a snapshot taken at deletion time
     * (AccountDeletionService) — only on the rows that are kept.
     *
     * The FK is dropped behind an existence check for the same reason as
     * 2026_08_17_120000: some hosts came up without the constraint at all.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payer_name')->nullable()->after('user_ref');
            $table->string('payer_email')->nullable()->after('payer_name');
        });

        if ($this->foreignKeyExists()) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Rows whose account is gone cannot satisfy NOT NULL; they would have been deleted
        // by the old cascade, so deleting them here reproduces the schema being restored.
        DB::table('payments')->whereNull('user_id')->delete();

        if ($this->foreignKeyExists()) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropColumn(['payer_name', 'payer_email']);
        });
    }

    private function foreignKeyExists(): bool
    {
        return collect(Schema::getForeignKeys('payments'))
            ->contains(fn (array $fk): bool => in_array('user_id', $fk['columns'], true));
    }
};
