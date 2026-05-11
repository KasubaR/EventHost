<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_tpl_cat', function (Blueprint $table) {
            $table->foreignId('invitation_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invitation_template_category_id')->constrained('invitation_template_categories')->cascadeOnDelete();
            $table->primary(
                ['invitation_template_id', 'invitation_template_category_id'],
                'inv_tpl_cat_primary'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_tpl_cat');
    }
};
