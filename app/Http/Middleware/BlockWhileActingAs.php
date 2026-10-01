<?php

namespace App\Http\Middleware;

use App\Services\ActingAsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route while an admin is acting as a client. Used on what must stay the
 * client's own: credentials, account deletion and every payment route (the client
 * pays for themselves) — and on the whole admin panel, so nothing admin-only is
 * reachable from inside the client's session. Only the exit route is exempt.
 *
 * Plain use 403s. With the `friendly` parameter a GET page is not a dead end: plan and
 * credit gates all redirect to billing, so the admin is sent back to where they were
 * with a note saying what to do instead (shown by the acting-as banner). Anything that
 * changes state stays a hard 403.
 */
class BlockWhileActingAs
{
    public function __construct(private readonly ActingAsService $acting) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! $this->acting->isActive($request) || $request->routeIs('admin.acting-as.destroy')) {
            return $next($request);
        }

        if ($mode === 'friendly' && $request->isMethodSafe()) {
            return redirect()
                ->back(fallback: route('events.index'))
                ->with('acting_notice', 'The client has to do this themselves: plans, credits and payments are always theirs to pay. '
                    .'If this is a plan or credit limit, exit this session, grant credits or change their plan from their admin page, then start again from their help request.');
        }

        abort(403, 'This is not available while you are acting as a client. Exit the session first.');
    }
}
