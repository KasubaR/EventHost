<?php

use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $lencoWebhookPath = trim((string) env('LENCO_WEBHOOK_PATH', 'lenco/webhook'), '/') ?: 'lenco/webhook';

        $middleware->validateCsrfTokens(except: [
            $lencoWebhookPath,
        ]);

        $middleware->alias([
            'admin.auth' => AdminAuthenticate::class,
            'account.active' => EnsureAccountIsActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
