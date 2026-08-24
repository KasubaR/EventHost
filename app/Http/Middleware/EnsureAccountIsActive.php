<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * LoginRequest already refuses a suspended account at the login screen, but
 * that check only runs once, at sign-in — it says nothing about a session
 * that was already open when an admin suspended the account afterwards.
 * This middleware re-checks status on every authenticated request so a
 * suspension takes effect immediately instead of waiting for the user's
 * next login.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status === 'suspended') {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been suspended. Contact support if you believe this is a mistake.',
            ]);
        }

        return $next($request);
    }
}
