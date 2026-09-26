<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * JSON sibling of App\Http\Controllers\Settings\AccountController (Slice E).
 * Same guard and deletion as the web controller (AccountDeletionService). Password
 * re-check uses the same Auth::guard('web')->validate() trick as
 * SecurityController (the implicit `current_password` rule assumes the
 * `web` guard). Revokes only the token used for this request — a delete
 * from one device shouldn't need every other logged-in device to have
 * already logged out for the check to make sense, and there is no session
 * to invalidate here as the web controller does.
 */
class AccountController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => $validated['password']])) {
            throw ValidationException::withMessages([
                'password' => trans('auth.password'),
            ]);
        }

        // Same guard, same deletion, as the web controller — see AccountDeletionService.
        // Still a 409 with a `message` key; only the wording widened.
        $blocker = app(AccountDeletionService::class)->delete($user);

        if ($blocker !== null) {
            return response()->json(['message' => AccountDeletionService::messageFor($blocker)], 409);
        }

        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Account deleted.']);
    }
}
