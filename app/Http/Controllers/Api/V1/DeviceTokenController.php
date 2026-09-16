<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Push-token registration (Slice E, new — no web equivalent). Idempotent on
 * `fcm_token` alone: it's unique across the table, so re-registering the same
 * token (app relaunch, token refresh callback firing again) updates one row
 * rather than creating a duplicate, and a token that moved to a different
 * account (reinstall, different user signed in on the same phone) is
 * reassigned to the new user rather than rejected — a stale user_id on an
 * unreachable account is actively wrong, not merely redundant.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
        ]);

        $token = DeviceToken::query()->updateOrCreate(
            ['fcm_token' => $validated['fcm_token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['id' => $token->id], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:255'],
        ]);

        // Scoped to the caller's own tokens — logging out on one account must
        // never delete a token another account re-registered on the same
        // device in the meantime.
        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('fcm_token', $validated['fcm_token'])
            ->delete();

        return response()->json(['message' => 'Device token removed.']);
    }
}
