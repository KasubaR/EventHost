<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless twin of EnsureAccountIsActive for Sanctum-authenticated /api/v1 routes.
 *
 * The web middleware force-logs-out a *session* the moment a suspended account is
 * detected. A Sanctum bearer token has no session to invalidate, and nothing today
 * revokes a suspended user's existing tokens except the 24h-cadence
 * `sanctum:prune-expired` command (which prunes by expiry, not by suspension) — so a
 * suspended host could otherwise keep mutating events for up to a day. This closes
 * that gap immediately: the first request that discovers the suspension revokes the
 * very token it was authenticated with.
 */
class EnsureSanctumAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status === 'suspended') {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your account has been suspended. Contact support if you believe this is a mistake.',
            ], 403);
        }

        return $next($request);
    }
}
