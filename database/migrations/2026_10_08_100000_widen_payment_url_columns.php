<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Astragate's hosted checkout link carries a signed token in its query string, which runs to
     * well over 1,000 characters, so 255 (ticket and contribution payments) and 500 (billing
     * payments) both overflow it. The value is a URL we only store and echo back: text is right.
     *
     * @var list<string>
     */
    private array $tables = ['payments', 'ticket_payments', 'contribution_payments'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'payment_url')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->text('payment_url')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'payment_url')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('payment_url', $table === 'payments' ? 500 : 255)->nullable()->change();
            });
        }
    }
};
