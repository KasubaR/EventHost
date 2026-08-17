<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rewrite FKs that the original create migration used to cascade-delete.
     * Fresh installs already get nullOnDelete from that create migration; this
     * only needs to change columns that are still NOT NULL (already-applied DBs).
     *
     * Guarded with an existence check rather than a bare dropForeign(): on at
     * least one deployment the table came up without the constraint at all
     * (non-InnoDB fallback on that host silently drops FK clauses), so an
     * unconditional drop fails with "check that it exists" and blocks the batch.
     */
    public function up(): void
    {
        Schema::table('ticket_revenue_entries', function (Blueprint $table) {
            if ($this->foreignKeyExists('ticket_revenue_entries', 'event_id')) {
                $table->dropForeign(['event_id']);
            }

            if ($this->foreignKeyExists('ticket_revenue_entries', 'ticket_order_id')) {
                $table->dropForeign(['ticket_order_id']);
            }
        });

        if ($this->eventIdIsRequired()) {
            Schema::table('ticket_revenue_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('event_id')->nullable()->change();
                $table->unsignedBigInteger('ticket_order_id')->nullable()->change();
            });
        }

        Schema::table('ticket_revenue_entries', function (Blueprint $table) {
            $table->foreign('event_id')->references('id')->on('events')->nullOnDelete();
            $table->foreign('ticket_order_id')->references('id')->on('ticket_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_revenue_entries', function (Blueprint $table) {
            if ($this->foreignKeyExists('ticket_revenue_entries', 'event_id')) {
                $table->dropForeign(['event_id']);
            }

            if ($this->foreignKeyExists('ticket_revenue_entries', 'ticket_order_id')) {
                $table->dropForeign(['ticket_order_id']);
            }
        });

        Schema::table('ticket_revenue_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable(false)->change();
            $table->unsignedBigInteger('ticket_order_id')->nullable(false)->change();
        });

        Schema::table('ticket_revenue_entries', function (Blueprint $table) {
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('ticket_order_id')->references('id')->on('ticket_orders')->cascadeOnDelete();
        });
    }

    private function eventIdIsRequired(): bool
    {
        $column = collect(Schema::getColumns('ticket_revenue_entries'))
            ->firstWhere('name', 'event_id');

        return $column !== null && ! ($column['nullable'] ?? false);
    }

    /**
     * Driver-agnostic existence check (works on MySQL, sqlite/tests, Postgres)
     * rather than a bare dropForeign() — the DB refuses to drop a constraint
     * that isn't there, and whether it's there varies by host/engine even for
     * a table this migration's own batch just created.
     */
    private function foreignKeyExists(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $fk): bool => in_array($column, $fk['columns'], true));
    }
};
