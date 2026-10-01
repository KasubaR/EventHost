<?php

namespace App\Http\Middleware;

use App\Services\ActingAsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 403s a route while an admin is acting as a client. Used on what must stay the
 * client's own: credentials, account deletion and every payment route (the client
 * pays for themselves) — and on the whole admin panel, so nothing admin-only is
 * reachable from inside the client's session. Only the exit route is exempt.
 */
class BlockWhileActingAs
{
    public function __construct(private readonly ActingAsService $acting) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->acting->isActive($request) && ! $request->routeIs('admin.acting-as.destroy')) {
            abort(403, 'This is not available while you are acting as a client. Exit the session first.');
        }

        return $next($request);
    }
}
