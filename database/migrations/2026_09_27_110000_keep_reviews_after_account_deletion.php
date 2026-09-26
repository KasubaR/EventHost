<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A host's review outlives their account, and the event it was written about
     * (plans/event-retention.md §6c). Both keys used to cascade, so deleting the account
     * — or the events under it — deleted a testimonial that may be on the homepage.
     * Both columns were already nullable (admin-authored reviews have neither); they only
     * need to null out instead of cascade. author_name / author_context snapshot who wrote
     * it, so the row still renders and the admin panel still lists it.
     *
     * Each FK is dropped behind an existence check for the reason given in
     * 2026_08_17_120000: some hosts came up without the constraint at all.
     */
    public function up(): void
    {
        $this->replaceForeignKeys(cascade: false);
    }

    public function down(): void
    {
        $this->replaceForeignKeys(cascade: true);
    }

    private function replaceForeignKeys(bool $cascade): void
    {
        foreach (['user_id' => 'users', 'event_id' => 'events'] as $column => $table) {
            if ($this->foreignKeyExists($column)) {
                Schema::table('reviews', function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropForeign([$column]);
                });
            }

            Schema::table('reviews', function (Blueprint $blueprint) use ($column, $table, $cascade) {
                $foreign = $blueprint->foreign($column)->references('id')->on($table);
                $cascade ? $foreign->cascadeOnDelete() : $foreign->nullOnDelete();
            });
        }
    }

    private function foreignKeyExists(string $column): bool
    {
        return collect(Schema::getForeignKeys('reviews'))
            ->contains(fn (array $fk): bool => in_array($column, $fk['columns'], true));
    }
};
