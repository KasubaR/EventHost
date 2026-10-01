<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Services\ActingAsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every web request. While an acting-as session is live it re-checks that
 * the admin is still signed in, the client is still active and the session has not
 * outlived its ceiling — and ends it the moment any of that stops being true.
 */
class EnforceActingAsSession
{
    public function __construct(private readonly ActingAsService $acting) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->acting->isActive($request)) {
            return $next($request);
        }

        $reason = $this->acting->invalidReason($request);

        if ($reason === null) {
            $this->enforceEventScope($request);

            return $next($request);
        }

        $returnUrl = $this->acting->stop($request);

        if (Auth::guard('admin')->check()) {
            return redirect($returnUrl ?? route('admin.dashboard'))->with('error', $reason);
        }

        return redirect()->route('admin.login')->withErrors(['email' => $reason]);
    }

    /**
     * A request about one event limits the session to that event: any other event's pages,
     * and creating a new one, are refused. A request not about an event has no such limit.
     */
    private function enforceEventScope(Request $request): void
    {
        $scoped = $this->acting->scopedEventId($request);

        if ($scoped === null) {
            return;
        }

        $event = $request->route('event');
        $eventId = $event instanceof Event ? $event->id : (is_scalar($event) ? (int) $event : null);

        if (($eventId !== null && $eventId !== $scoped) || $request->routeIs('events.create', 'events.store')) {
            abort(403, 'This help request is about one event only. Exit the session to work on anything else.');
        }
    }
}
