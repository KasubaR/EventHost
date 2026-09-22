<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Android/iOS push-token registry (Slice E, plans/android-implementation.md
        // §0.6). One row per (user, device token) — `fcm_token` is unique on its own
        // (a token belongs to exactly one installed app instance at a time), so a
        // reinstall on a different account reassigns the existing row's user_id
        // rather than erroring; see Api\V1\DeviceTokenController::store().
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('fcm_token')->unique();
            $table->string('platform', 20);
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
