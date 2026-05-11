<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('invitation_template_id')
                ->nullable()
                ->after('user_id')
                ->constrained('invitation_templates')
                ->nullOnDelete();
            $table->json('invitation_customization')->nullable()->after('invitation_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['invitation_template_id']);
            $table->dropColumn(['invitation_template_id', 'invitation_customization']);
        });
    }
};
