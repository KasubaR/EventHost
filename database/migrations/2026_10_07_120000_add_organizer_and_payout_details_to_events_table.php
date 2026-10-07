<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('events', 'organizer_name')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            // Who guests can ask about the event itself (wizard step "Organizer Details").
            $table->string('organizer_name', 120)->nullable()->after('host_contact_phone');
            $table->string('organizer_phone', 40)->nullable()->after('organizer_name');
            $table->string('organizer_email')->nullable()->after('organizer_phone');
            // Whether the three above show on the public ticket page's Contact & Help card.
            $table->boolean('organizer_details_public')->default(true)->after('organizer_email');

            // Where EventHost pays ticket revenue out. Holder name and number are
            // encrypted by the model cast, hence text columns.
            $table->text('payout_account_name')->nullable()->after('organizer_details_public');
            $table->text('payout_account_number')->nullable()->after('payout_account_name');
            $table->string('payout_bank', 120)->nullable()->after('payout_account_number');
            $table->string('payout_branch', 120)->nullable()->after('payout_bank');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('events', 'organizer_name')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn([
                'organizer_name',
                'organizer_phone',
                'organizer_email',
                'organizer_details_public',
                'payout_account_name',
                'payout_account_number',
                'payout_bank',
                'payout_branch',
            ]);
        });
    }
};
