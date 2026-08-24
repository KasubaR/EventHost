<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('is_published');
            $table->timestamp('invitation_paused_at')->nullable()->after('cancelled_at');
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'invitation_paused_at', 'deleted_at']);
        });
    }
};
