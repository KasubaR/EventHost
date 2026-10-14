<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who changed what on a guest, and when (plans/guest-editing-gaps.md Phase 5). RSVP answers have their own history (`rsvp_changes`); this
     * is the guest's own details: name, email, phone, group, table, plus-one permission and link replacements. A guest's delete takes
     * its edits with it; deleting the editor's account keeps the row.
     */
    public function up(): void
    {
        Schema::create('guest_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('changes');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['guest_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_edits');
    }
};
