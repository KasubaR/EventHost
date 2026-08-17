<?php

use App\Enums\CommissionMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
            // Extra the buyer pays on top of face_value. 0.00 when the host
            // absorbs commission; equal to commission_amount on pass-through.
            // Snapshotted at checkout — never derived later from today's rate.
            $table->decimal('buyer_fee', 12, 2)->default('0.00')->after('commission_amount');
        });

        DB::table('ticket_orders')
            ->where('commission_mode', CommissionMode::PassThrough->value)
            ->update(['buyer_fee' => DB::raw('commission_amount')]);

        if (Schema::hasTable('ticket_revenue_entries')) {
            $addBuyerFee = ! Schema::hasColumn('ticket_revenue_entries', 'buyer_fee');
            $addBuyerTotal = ! Schema::hasColumn('ticket_revenue_entries', 'buyer_total');

            if ($addBuyerFee || $addBuyerTotal) {
                Schema::table('ticket_revenue_entries', function (Blueprint $table) use ($addBuyerFee, $addBuyerTotal) {
                    if ($addBuyerFee) {
                        $table->decimal('buyer_fee', 12, 2)->default('0.00')->after('platform_fee');
                    }
                    if ($addBuyerTotal) {
                        $table->decimal('buyer_total', 12, 2)->default('0.00')->after('buyer_fee');
                    }
                });
            }

            foreach (DB::table('ticket_revenue_entries')->whereNotNull('ticket_order_id')->cursor() as $entry) {
                $order = DB::table('ticket_orders')->where('id', $entry->ticket_order_id)->first();
                if ($order === null) {
                    continue;
                }

                DB::table('ticket_revenue_entries')->where('id', $entry->id)->update([
                    'buyer_fee' => $order->buyer_fee,
                    'buyer_total' => $order->buyer_total,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
            $table->dropColumn('buyer_fee');
        });
    }
};
