<?php

use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('product_kind', 20)->default(EventProductKind::Invitation->value)->after('event_type');
            $table->string('ticketing_status', 20)->default(TicketingStatus::NotApplicable->value)->after('product_kind');
            $table->string('commission_mode', 20)->nullable()->after('ticketing_status');
            $table->timestamp('ticketing_submitted_at')->nullable()->after('commission_mode');
            $table->timestamp('ticketing_reviewed_at')->nullable()->after('ticketing_submitted_at');
            $table->foreignId('ticketing_reviewed_by')->nullable()->after('ticketing_reviewed_at')
                ->constrained('admins')->nullOnDelete();
            $table->text('ticketing_rejection_note')->nullable()->after('ticketing_reviewed_by');
            $table->date('agreed_payout_on')->nullable()->after('ticketing_rejection_note');

            $table->index(['product_kind', 'ticketing_status']);
        });

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('quantity')->nullable();
            $table->timestamp('sales_starts_at')->nullable();
            $table->timestamp('sales_ends_at')->nullable();
            $table->unsignedTinyInteger('min_per_order')->default(1);
            $table->unsignedTinyInteger('max_per_order')->default(10);
            $table->string('image_path')->nullable();
            $table->text('terms')->nullable();
            $table->text('refund_policy')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['event_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['product_kind', 'ticketing_status']);
            $table->dropConstrainedForeignId('ticketing_reviewed_by');
            $table->dropColumn([
                'product_kind',
                'ticketing_status',
                'commission_mode',
                'ticketing_submitted_at',
                'ticketing_reviewed_at',
                'ticketing_rejection_note',
                'agreed_payout_on',
            ]);
        });
    }
};
