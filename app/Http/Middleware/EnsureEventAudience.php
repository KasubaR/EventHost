<?php

namespace App\Http\Middleware;

use App\Enums\EventAudience;
use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth for the Public portal's ticketed-only sub-routes
 * (plans/public-private-portals.md Phase 3b). Every controller behind this
 * already 404s on its own (abort_unless($event->isTicketed())), and a
 * ticketed event is always public audience (Event's saving hook), so this
 * never actually fires today. It exists so a route here can never silently
 * serve a private-audience event even if a future controller forgets its
 * own guard.
 */
class EnsureEventAudience
{
    public function handle(Request $request, Closure $next, string $audience): Response
    {
        $event = $request->route('event');

        abort_unless($event instanceof Event && $event->audience === EventAudience::from($audience), 404);

        return $next($request);
    }
}
