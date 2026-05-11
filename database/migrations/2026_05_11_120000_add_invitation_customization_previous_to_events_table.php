<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('events', 'invitation_customization_previous')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->json('invitation_customization_previous')->nullable()->after('invitation_customization');
            $table->timestamp('invitation_customization_previous_captured_at')->nullable()->after('invitation_customization_previous');
            $table->unsignedBigInteger('invitation_customization_previous_captured_by_user_id')
                ->nullable()
                ->after('invitation_customization_previous_captured_at');

            $table->foreign(
                'invitation_customization_previous_captured_by_user_id',
                'evt_inv_cust_prev_uid_fk'
            )->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('events', 'invitation_customization_previous')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropForeign('evt_inv_cust_prev_uid_fk');
            $table->dropColumn([
                'invitation_customization_previous',
                'invitation_customization_previous_captured_at',
                'invitation_customization_previous_captured_by_user_id',
            ]);
        });
    }
};
