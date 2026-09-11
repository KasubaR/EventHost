<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * JSON sibling of App\Http\Controllers\Auth\AuthenticatedSessionController. Cannot reuse
 * LoginRequest::authenticate() as-is (web version calls Auth::attempt(), which logs into the
 * 'web' session guard, and invalidates a session on the suspended-account branch — both throw
 * on a stateless `api` request). Uses Auth::guard('web')->validate() instead, which checks
 * credentials with no session/login side effect (Illuminate\Auth\SessionGuard::validate()),
 * then issues a Sanctum token by hand. Every other business rule is replicated exactly: the
 * 5-attempt (email+ip) lockout, the generic "don't reveal suspension" failure message, and the
 * raw last_login_at/last_login_ip update.
 */
class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->validate($credentials)) {
            RateLimiter::hit($request->throttleKey(), 60);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        // Same generic failure as a bad password — a suspended account must never be
        // distinguishable from "wrong credentials" to the caller, matching web exactly.
        if ($user === null || $user->status === 'suspended') {
            RateLimiter::hit($request->throttleKey(), 60);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($request->throttleKey());

        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function deviceName(Request $request): string
    {
        $name = $request->input('device_name');

        return is_string($name) && $name !== '' ? $name : 'android';
    }
}
