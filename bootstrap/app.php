<?php

use App\Exceptions\GuestLimitReachedException;
use App\Exceptions\RsvpBusyException;
use App\Exceptions\RsvpCheckedInException;
use App\Exceptions\RsvpClosedException;
use App\Exceptions\RsvpUnavailableException;
use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\BlockWhileActingAs;
use App\Http\Middleware\EnforceActingAsSession;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEventAudience;
use App\Http\Middleware\EnsureSanctumAccountIsActive;
use App\Http\Middleware\NormalizeGuestToken;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Phase 0.1: bearer-token JSON API for the Android app. No statefulApi()/throttleApi()
        // call anywhere in this file, so the `api` middleware group stays exactly
        // [SubstituteBindings::class] — no SPA-cookie/CSRF machinery, no undefined default
        // rate limiter. Endpoints bring their own named throttle, same convention as web.php.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/astragate.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $lencoWebhookPath = trim((string) env('LENCO_WEBHOOK_PATH', 'lenco/webhook'), '/') ?: 'lenco/webhook';

        $middleware->validateCsrfTokens(except: [
            $lencoWebhookPath,
            'webhooks/twilio/whatsapp',
            'webhooks/astragate/*',
        ]);

        // After StartSession, so the session is readable. Ends an acting-as session the
        // moment it stops being valid (plans/admin-create-events.md Step 1.3).
        $middleware->web(append: [EnforceActingAsSession::class]);

        $middleware->alias([
            'acting-as.block' => BlockWhileActingAs::class,
            'admin.auth' => AdminAuthenticate::class,
            'account.active' => EnsureAccountIsActive::class,
            'audience' => EnsureEventAudience::class,
            'guest.token' => NormalizeGuestToken::class,
            // Slice C1 — the Sanctum-token twin of account.active. See
            // EnsureSanctumAccountIsActive's docblock for why this exists separately.
            'sanctum.active' => EnsureSanctumAccountIsActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The guest-facing page a failed RSVP POST should go back to, from the route it came in on.
        $rsvpPageFor = function (Request $request): ?string {
            $route = $request->route();
            $name = (string) $route?->getName();

            return match (true) {
                str_starts_with($name, 'rsvp.token') && $route?->parameter('token') !== null => route('rsvp.token.show', ['token' => $route->parameter('token')]),
                str_starts_with($name, 'group-rsvp') && $route?->parameter('token') !== null => route('group-rsvp.show', ['token' => $route->parameter('token')]),
                $route?->parameter('slug') !== null => route('rsvp.open.show', ['slug' => $route->parameter('slug')]),
                default => null,
            };
        };

        // A late RSVP is a refusal with an explanation, never a bare 403 (plans/rsvp-deadline-fixes.md G4).
        // JSON clients get 403 with a stable `code`; web pages go back to the page that explains it.
        // A database error carries the SQL, its bound values and the database name. None of that
        // belongs in front of a visitor, and the checkout pages print a JSON `message` verbatim, so a
        // failed insert used to read as a wall of SQL. Reporting is untouched (it is still logged in
        // full); only the response changes. A developer on a local machine still sees the real error.
        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->expectsJson() || app()->isLocal()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong on our side. Please try again in a moment. If it keeps happening, contact support.',
            ], 500);
        });

        $exceptions->render(function (RsvpClosedException $e, Request $request) use ($rsvpPageFor) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'rsvp_closed',
                    'can_reduce' => $e->mayReduce,
                ], 403);
            }

            return ($rsvpPageFor($request) !== null ? redirect($rsvpPageFor($request)) : redirect()->back(fallback: url('/')))
                ->with('rsvp_closed', $e->getMessage());
        });

        // The database could not take the event's lock in time. Nothing was saved; ask the guest to send it again.
        $exceptions->render(function (RsvpBusyException $e, Request $request) use ($rsvpPageFor) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'rsvp_busy',
                ], 503)->header('Retry-After', '5');
            }

            $target = $rsvpPageFor($request);

            return ($target !== null ? redirect($target) : redirect()->back(fallback: url('/')))
                ->withInput($request->except('_token'))
                ->withErrors(['status' => $e->getMessage()]);
        });

        // Already checked in: the answer cannot be cancelled or reduced from here. Shown on the form the guest used.
        $exceptions->render(function (RsvpCheckedInException $e, Request $request) use ($rsvpPageFor) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'rsvp_checked_in'], 403);
            }

            $target = $rsvpPageFor($request);

            return ($target !== null ? redirect($target) : redirect()->back(fallback: url('/')))
                ->withInput($request->except('_token'))
                ->withErrors(['status' => $e->getMessage()]);
        });

        // Deleted, cancelled, paused or full since the form was opened: the GET page for the same link already
        // renders the right status view, so go back to it instead of showing the generic 403 (T4).
        $exceptions->render(function (RsvpUnavailableException $e, Request $request) use ($rsvpPageFor) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'rsvp_unavailable',
                ], 403);
            }

            $target = $rsvpPageFor($request);

            return $target !== null ? redirect($target) : redirect()->back(fallback: url('/'));
        });

        // A plus-one that does not fit the guest limit stays a 422 on `status`; this adds what still fits.
        // JSON gets `seats_left`; the web form comes back with the count preselected to the seats that fit.
        $exceptions->render(function (GuestLimitReachedException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                    'seats_left' => $e->seatsLeft,
                ], 422);
            }

            $input = $request->input();
            if ($e->seatsLeft > 0) {
                $input['attendee_count'] = min($e->seatsLeft, $e->requestedSeats);
            }

            return redirect($e->redirectTo ?? url()->previous())
                ->withInput($input)
                ->withErrors($e->errors(), $e->errorBag);
        });

        // A guest's personal or group link that matches nobody (mistyped, cut off, replaced by the host, guest removed)
        // gets a page that says what to do, not the site's "event isn't public" 404. Pages only: the image routes
        // and JSON keep a plain 404. plans/rsvp-token-edge-cases.md T1.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }

            $name = (string) $request->route()?->getName();
            $isGuestLinkPage = in_array($name, [
                'rsvp.token.show', 'rsvp.token.thanks', 'rsvp.token.pass', 'rsvp.token.store',
                'group-rsvp.show', 'group-rsvp.store',
            ], true);

            return $isGuestLinkPage ? response()->view('rsvp.link-not-found', [], 404) : null;
        });

        // A stale CSRF token nearly always means the same form was sent twice: the
        // first send succeeded and regenerated the session token (logging in does
        // exactly that), so the duplicate arrives carrying a token that no longer
        // matches. Send the user back where they came from instead of showing the
        // raw "Page Expired" screen — if the first send signed them in, the guest
        // middleware forwards them on to the dashboard from there.
        //
        // Note: this has to match on HttpException rather than TokenMismatchException.
        // Handler::render() runs prepareException() first, which has already turned the
        // token mismatch into a plain HttpException by the time callbacks are consulted.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Please refresh the page and try again.',
                ], 419);
            }

            // A guest RSVP form has no `email` error slot (the token and group forms don't render one) and a
            // timed-out session is not a login problem: say it where the form shows errors, keep the answers.
            $routeName = (string) $request->route()?->getName();
            if (str_starts_with($routeName, 'rsvp.') || str_starts_with($routeName, 'group-rsvp.')) {
                return redirect()
                    ->back(fallback: url('/'))
                    ->withInput($request->except(['_token']))
                    ->withErrors(['status' => 'This page timed out before your response was sent. Your answers are still filled in, so please send it again.']);
            }

            $redirect = redirect()
                ->back(fallback: route('login'))
                ->withInput($request->except(['password', 'password_confirmation', '_token']));

            if ($request->user() !== null) {
                return $redirect;
            }

            return $redirect->withErrors([
                'email' => 'Your session expired for security reasons. Please try again.',
            ]);
        });
    })->create();
