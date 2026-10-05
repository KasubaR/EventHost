<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('events', 'invitation_customization_revision')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->unsignedInteger('invitation_customization_revision')->default(0)->after('invitation_customization_previous');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('events', 'invitation_customization_revision')) {
            return;
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('invitation_customization_revision');
        });
    }
};
