<?php

namespace App\Http\Middleware;

use App\Models\Guest;
use App\Models\GuestGroup;
use App\Support\GuestLinkToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cleans the `{token}` route parameter of a guest-facing link (see GuestLinkToken) before any controller or
 * form request reads it. Usage: `guest.token` (a personal link), `guest.token:group` (a group link), and a
 * second argument `quiet` for the JSON API, which must not redirect.
 *
 * - Not a possible token (empty, too long, odd characters): 404 here, with no database query.
 * - Cleaned token differs from the raw one: a GET for a link that really exists is redirected to the canonical
 *   URL, so a pasted `…token).` just works. Anything else carries on with the cleaned value, so a POST is not
 *   bounced (a redirect would drop the form) and an unknown token still ends in the normal not-found.
 *
 * plans/rsvp-token-edge-cases.md Phase 2.
 */
class NormalizeGuestToken
{
    public function handle(Request $request, Closure $next, string $kind = 'guest', ?string $mode = null): Response
    {
        $route = $request->route();
        $raw = $route?->parameter('token');

        if (! is_string($raw)) {
            return $next($request);
        }

        $clean = GuestLinkToken::clean($raw);

        if ($clean === null) {
            abort(404);
        }

        if ($clean === $raw) {
            return $next($request);
        }

        $route->setParameter('token', $clean);

        if ($mode !== 'quiet' && $request->isMethod('GET') && $this->exists($kind, $clean) && $route->getName() !== null) {
            return redirect()->route($route->getName(), $route->parameters() + $request->query());
        }

        return $next($request);
    }

    private function exists(string $kind, string $token): bool
    {
        return $kind === 'group'
            ? GuestGroup::query()->where('rsvp_token', $token)->exists()
            : Guest::query()->where('invitation_token', $token)->exists();
    }
}
