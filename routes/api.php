<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\EventContributionController;
use App\Http\Controllers\Api\V1\EventGalleryController;
use App\Http\Controllers\Api\V1\EventTicketCheckoutController;
use App\Http\Controllers\Api\V1\EventTicketPurchaseController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PublicEventController;
use App\Http\Controllers\Api\V1\RsvpController;
use App\Http\Controllers\Api\V1\TableUploadController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Phase 0.1 — pure plumbing. Slice A (0.2) — auth, /me, discover, public event read.
// Slice B1 (0.3) — RSVP. Slice B2 — tickets. Slice B3 (below) — contributions, table
// photo upload, gallery. See plans/android-app.md and EventHostAndriodApp/implementation.md.
//
// Guard convention for everything added after this file grows: Sanctum **stateless** bearer
// tokens via `auth:sanctum` per route/group. `bootstrap/app.php` deliberately does not call
// `statefulApi()` — this API has no first-party SPA/cookie consumer, only the native app — so
// there is no CSRF/stateful-cookie machinery to route around here.
Route::prefix('v1')->group(function (): void {
    // Unauthenticated on purpose: proves the /api/v1 path resolves before Slice A adds a
    // token-issuing login endpoint to test an authenticated route against.
    Route::get('/ping', function (Request $request) {
        return response()->json([
            'status' => 'ok',
            'time' => now()->toIso8601String(),
        ]);
    })->name('api.v1.ping');

    // Slice A — see plans/android-app.md §3 and the Slice A plan for the full rationale behind
    // each endpoint's design (why login can't reuse the web LoginRequest as-is, why register
    // gets a new named throttle and login doesn't, why email-verify-by-link stays a web route).
    Route::prefix('auth')->name('api.v1.auth.')->group(function (): void {
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware('throttle:api-auth-register')
            ->name('register');
        // Rate limiting is internal to the stateless LoginRequest (5 attempts per email+ip,
        // cleared on success), not route-level throttle middleware — see that class's docblock.
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->name('login');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->middleware('auth:sanctum')
            ->name('logout');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->name('forgot-password');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->name('reset-password');
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware(['auth:sanctum', 'throttle:6,1'])
            ->name('verification.send');
    });

    Route::get('/me', [MeController::class, 'show'])
        ->middleware('auth:sanctum')
        ->name('api.v1.me');

    Route::get('/discover', [PublicEventController::class, 'index'])
        ->name('api.v1.discover');
    Route::get('/events/{slug}', [PublicEventController::class, 'show'])
        ->name('api.v1.events.show');
    Route::get('/events/{slug}/calendar.ics', [PublicEventController::class, 'ics'])
        ->name('api.v1.events.ics');

    // Slice B1 — RSVP (token + open). No auth:sanctum: guest-only on the web side too. Reuses
    // the existing rsvp-submit rate limiter (ip+token / ip+slug, 10/min) verbatim — see the
    // Slice B1 plan for why storeByToken/storeOpen return the confirmation body directly
    // (200) instead of the web flow's redirect+session-flash.
    Route::prefix('rsvp')->name('api.v1.rsvp.')->group(function (): void {
        Route::get('/{token}', [RsvpController::class, 'showByToken'])->name('token.show');
        Route::post('/{token}', [RsvpController::class, 'storeByToken'])
            ->middleware('throttle:rsvp-submit')
            ->name('token.store');
    });

    Route::prefix('events/{slug}/rsvp')->name('api.v1.rsvp.open.')->group(function (): void {
        Route::get('/', [RsvpController::class, 'showOpen'])->name('show');
        Route::post('/', [RsvpController::class, 'storeOpen'])
            ->middleware('throttle:rsvp-submit')
            ->name('store');
    });

    // Slice B2 — Tickets. No auth:sanctum: guest checkout on the web side, guest checkout here
    // too (same posture as B1's RSVP routes). Cart id is minted server-side in hold() and
    // returned to the client instead of written to a session (web's TicketCart) — every later
    // call carries cart_id explicitly. See the Slice B2 plan for why.
    Route::prefix('events/{slug}/tickets')->name('api.v1.tickets.')->group(function (): void {
        Route::get('/', [EventTicketPurchaseController::class, 'show'])->name('show');
        Route::post('/hold', [EventTicketPurchaseController::class, 'hold'])
            ->middleware('throttle:ticket-hold')
            ->name('hold');
        Route::get('/checkout', [EventTicketCheckoutController::class, 'show'])->name('checkout.show');
        Route::post('/checkout', [EventTicketCheckoutController::class, 'store'])
            ->middleware('throttle:ticket-checkout')
            ->name('checkout.store');
    });

    Route::prefix('tickets/orders/{orderReference}')
        ->where(['orderReference' => '[A-Za-z0-9_\-]{1,128}'])
        ->name('api.v1.tickets.orders.')
        ->group(function (): void {
            Route::get('/', [EventTicketCheckoutController::class, 'status'])->name('show');
            Route::get('/verify', [EventTicketCheckoutController::class, 'verify'])
                ->middleware('throttle:ticket-verify')
                ->name('verify');
        });

    Route::prefix('tickets/wallet/{token}')->name('api.v1.tickets.wallet.')->group(function (): void {
        Route::get('/', [TicketController::class, 'show'])->name('show');
        Route::get('/download', [TicketController::class, 'download'])
            ->middleware('throttle:ticket-download')
            ->name('download');
    });

    // Slice B3 — Contributions, table photo upload, gallery. No auth:sanctum: guest-only on the
    // web side too, same posture as B1/B2. Reuses contribution-checkout/contribution-verify/
    // table-upload rate limiters verbatim; gallery stays unthrottled to match its web twin.
    Route::prefix('events/{slug}/contribute')->name('api.v1.contribute.')->group(function (): void {
        Route::get('/', [EventContributionController::class, 'show'])->name('show');
        Route::post('/', [EventContributionController::class, 'store'])
            ->middleware('throttle:contribution-checkout')
            ->name('store');
    });

    Route::prefix('contributions/{reference}')
        ->where(['reference' => '[A-Za-z0-9_\-]{1,128}'])
        ->name('api.v1.contributions.')
        ->group(function (): void {
            Route::get('/', [EventContributionController::class, 'status'])->name('show');
            Route::post('/pay', [EventContributionController::class, 'pay'])
                ->middleware('throttle:contribution-checkout')
                ->name('pay');
            Route::get('/verify', [EventContributionController::class, 'verify'])
                ->middleware('throttle:contribution-verify')
                ->name('verify');
        });

    Route::prefix('events/{slug}/table/{code}')->name('api.v1.table.')->group(function (): void {
        Route::get('/', [TableUploadController::class, 'show'])->name('show');
        Route::post('/photos', [TableUploadController::class, 'store'])
            ->middleware('throttle:table-upload')
            ->name('photos.store');
    });

    Route::prefix('events/{slug}/gallery')->name('api.v1.gallery.')->group(function (): void {
        Route::get('/', [EventGalleryController::class, 'show'])->name('show');
        Route::get('/feed', [EventGalleryController::class, 'feed'])->name('feed');
    });
});
