<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('credits_fulfilled_at')->nullable()->after('notified_at');
            $table->timestamp('credits_reversed_at')->nullable()->after('credits_fulfilled_at');
        });

        DB::table('payments')
            ->where('status', 'completed')
            ->whereNotNull('notified_at')
            ->whereNull('credits_fulfilled_at')
            ->update(['credits_fulfilled_at' => DB::raw('notified_at')]);

        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->unique(['payment_id', 'reason'], 'credit_transactions_payment_reason_unique');
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->dropUnique('credit_transactions_payment_reason_unique');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['credits_fulfilled_at', 'credits_reversed_at']);
        });
    }
};
