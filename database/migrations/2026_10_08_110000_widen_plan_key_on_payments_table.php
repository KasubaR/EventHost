<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `public_registration_quote` is 25 characters and the column was 24. SQLite does not enforce
     * string lengths, so tests passed; MySQL in strict mode rejects the insert, which would have
     * failed every free-registration quote payment.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('plan_key', 64)->change();
        });
    }

    public function down(): void
    {
        // Left wide: narrowing would truncate or fail on rows that already hold a long key.
    }
};
