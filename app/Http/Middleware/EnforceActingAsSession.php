<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\User;
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

            return $this->audited($request, $next);
        }

        $returnUrl = $this->acting->stop($request, $reason);

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

    /**
     * Route names whose audit entry is something other than the route name itself.
     * events.store is absent on purpose: the Event model's created hook logs it, with the new id.
     *
     * @var array<string, string>
     */
    private const ACTIONS = [
        'events.update' => 'event_updated',
        'events.publish' => 'event_published',
        'events.pause' => 'event_paused',
        'events.cancel' => 'event_cancelled',
        'events.destroy' => 'event_deleted',
        'events.restore' => 'event_restored',
    ];

    /**
     * One middleware tags every state-changing request made while acting as a client, instead
     * of each controller remembering to. Failed and validation-rejected requests are not
     * recorded: nothing changed. A change in the client's credit balance gets its own entry.
     */
    private function audited(Request $request, Closure $next): Response
    {
        $mutating = ! $request->isMethodSafe();
        $clientId = Auth::guard('web')->id();
        $creditsBefore = $mutating ? User::query()->whereKey($clientId)->value('event_credits') : null;

        $response = $next($request);

        if (! $mutating || ! $this->acting->isActive($request) || $response->getStatusCode() >= 400
            || $request->session()->has('errors')) {
            return $response;
        }

        $route = $request->route();
        $name = $route?->getName();

        if ($name === null || $name === 'events.store') {
            return $response;
        }

        $event = $route->parameter('event');
        $eventId = $event instanceof Event ? $event->id : (is_scalar($event) ? (int) $event : null);

        $this->acting->log($request, self::ACTIONS[$name] ?? $name, $eventId, ['method' => $request->method()]);

        $creditsAfter = User::query()->whereKey($clientId)->value('event_credits');

        if ($creditsBefore !== null && $creditsAfter !== null && (int) $creditsAfter !== (int) $creditsBefore) {
            $this->acting->log($request, $creditsAfter < $creditsBefore ? 'credits_spent' : 'credits_added', $eventId, [
                'before' => (int) $creditsBefore,
                'after' => (int) $creditsAfter,
            ]);
        }

        return $response;
    }
}
