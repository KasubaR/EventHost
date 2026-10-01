<?php

namespace App\Http\Middleware;

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
            return $next($request);
        }

        $returnUrl = $this->acting->stop($request);

        if (Auth::guard('admin')->check()) {
            return redirect($returnUrl ?? route('admin.dashboard'))->with('error', $reason);
        }

        return redirect()->route('admin.login')->withErrors(['email' => $reason]);
    }
}
