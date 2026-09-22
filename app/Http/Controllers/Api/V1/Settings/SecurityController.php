<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * JSON sibling of App\Http\Controllers\Auth\PasswordController::update()
 * (Slice E) — that method lives on `routes/auth.php`, guarded by the `web`
 * session, so it isn't reachable with a Bearer token as-is. Can't rely on the
 * implicit `current_password` validation rule here either: its default guard
 * resolution assumes the request is authenticated on the `web` guard, which
 * isn't true for a stateless `sanctum` request — same reasoning
 * Api\V1\Auth\AuthenticatedSessionController::store() already documents for
 * why it calls Auth::guard('web')->validate() by hand instead of
 * Auth::attempt().
 */
class SecurityController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => $validated['current_password']])) {
            throw ValidationException::withMessages([
                'current_password' => trans('auth.password'),
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        return response()->json(['message' => 'Password updated.']);
    }
}
