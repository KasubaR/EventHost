<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->foreignId('guest_group_id')->nullable()->after('event_id')->constrained('guest_groups')->nullOnDelete();
            $table->boolean('invitation_sent')->default(false)->after('plus_one_allowed');
            $table->timestamp('invitation_sent_at')->nullable()->after('invitation_sent');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_group_id');
            $table->dropColumn(['invitation_sent', 'invitation_sent_at']);
        });
    }
};
