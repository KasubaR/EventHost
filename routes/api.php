<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventCheckInController;
use App\Http\Controllers\Api\V1\EventChooseTemplateController;
use App\Http\Controllers\Api\V1\EventContributionController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventGalleryController;
use App\Http\Controllers\Api\V1\EventInvitationDesignController;
use App\Http\Controllers\Api\V1\EventInvitationMediaController;
use App\Http\Controllers\Api\V1\EventPreviewController;
use App\Http\Controllers\Api\V1\EventStaffController;
use App\Http\Controllers\Api\V1\EventStaffLinkController;
use App\Http\Controllers\Api\V1\EventTableController;
use App\Http\Controllers\Api\V1\EventTicketCheckInController;
use App\Http\Controllers\Api\V1\EventTicketCheckoutController;
use App\Http\Controllers\Api\V1\EventTicketDashboardController;
use App\Http\Controllers\Api\V1\EventTicketingController;
use App\Http\Controllers\Api\V1\EventTicketManagementController;
use App\Http\Controllers\Api\V1\EventTicketPurchaseController;
use App\Http\Controllers\Api\V1\EventTicketRevenueController;
use App\Http\Controllers\Api\V1\EventTicketTypeController;
use App\Http\Controllers\Api\V1\GuestBulkActionController;
use App\Http\Controllers\Api\V1\GuestController;
use App\Http\Controllers\Api\V1\GuestGroupController;
use App\Http\Controllers\Api\V1\GuestImportController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PublicEventController;
use App\Http\Controllers\Api\V1\RsvpController;
use App\Http\Controllers\Api\V1\StaffInvitationController;
use App\Http\Controllers\Api\V1\TableUploadController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Phase 0.1 — pure plumbing. Slice A (0.2) — auth, /me, discover, public event read.
// Slice B1 (0.3) — RSVP. Slice B2 — tickets. Slice B3 — contributions, table photo
// upload, gallery. Slice C1 (0.4) — host dashboard, event CRUD/lifecycle, preview,
// template choose. Slice C2 — invitation design, media staging. Slice C3 — guests,
// groups, CSV/bulk/export/QR, tables. Slice D (0.5, below) — check-in scanning
// (guest + ticket), staff scanner links, staff accounts + invitations, ticket
// types/ticketing submit, ticket management, revenue (read-only).
// See plans/android-app.md and EventHostAndriodApp/implementation.md.
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

    // Slice D — staff-invitation accept flow. Top-level and (mostly) unauthenticated,
    // same posture as auth/register: this is the one new Android App Link this slice
    // adds (`/staff/invitations/{token}`), letting an invited Manager/Check-in staffer
    // accept from the app instead of a desktop browser. See
    // App\Http\Controllers\Api\V1\StaffInvitationController's docblock for the
    // twin-path shape (new account vs already-registered "confirm").
    Route::prefix('staff-invitations/{token}')->name('api.v1.staff-invitations.')->group(function (): void {
        Route::get('/', [StaffInvitationController::class, 'show'])->name('show');
        Route::post('/', [StaffInvitationController::class, 'store'])->name('store');
        Route::post('/confirm', [StaffInvitationController::class, 'confirm'])
            ->middleware('auth:sanctum')
            ->name('confirm');
    });
});

// Slice C1 — host dashboard: stats, event CRUD, lifecycle, preview, template choose.
// Every route here is auth:sanctum + sanctum.active, unlike every prior slice's guest-facing
// routes — see the Slice C1 plan for the suspended-token-revocation rationale. Policy checks
// are the *same* EventPolicy the web routes use (authorizeResource in the constructor, or an
// explicit $this->authorize() call) — Sanctum's $request->user() resolves identically to the
// session guard's, so zero policy code changes were needed.
//
// Deliberately namespaced under `host/` rather than bare `/events`: Slice A already shipped
// GET /api/v1/events/{slug} (public, by slug) — a bare GET /api/v1/events/{event} (host, by
// numeric id) would collide with that exact URL shape. `host/events` avoids the collision now
// and for every future host-scoped {event} route C2/C3 add, rather than special-casing one route.
Route::prefix('v1/host')->middleware(['auth:sanctum', 'sanctum.active'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('api.v1.host.dashboard');

    Route::prefix('events')->name('api.v1.host.events.')->group(function (): void {
        Route::get('/', [EventController::class, 'index'])->name('index');
        Route::post('/', [EventController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('store');

        Route::get('/{event}', [EventController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], '/{event}', [EventController::class, 'update'])->name('update');
        Route::delete('/{event}', [EventController::class, 'destroy'])->name('destroy');

        Route::post('/{event}/restore', [EventController::class, 'restore'])
            ->withTrashed()
            ->name('restore');

        Route::patch('/{event}/publish', [EventController::class, 'publish'])->name('publish');
        Route::patch('/{event}/pause', [EventController::class, 'pause'])->name('pause');
        Route::patch('/{event}/resume', [EventController::class, 'resume'])->name('resume');
        Route::patch('/{event}/cancel', [EventController::class, 'cancel'])->name('cancel');
        Route::patch('/{event}/uncancel', [EventController::class, 'uncancel'])->name('uncancel');

        Route::get('/{event}/preview', [EventPreviewController::class, 'show'])->name('preview');

        Route::get('/{event}/choose-template', [EventChooseTemplateController::class, 'index'])->name('choose-template.index');
        Route::patch('/{event}/choose-template', [EventChooseTemplateController::class, 'update'])->name('choose-template.update');

        // Slice C2 — invitation design + media staging. Reuses the invitation-design /
        // invitation-media named rate limiters verbatim (both keyed on the authenticated
        // user's id already, per AppServiceProvider::boot()).
        Route::get('/{event}/design', [EventInvitationDesignController::class, 'show'])->name('design.show');
        Route::patch('/{event}/design', [EventInvitationDesignController::class, 'update'])
            ->middleware('throttle:invitation-design')
            ->name('design.update');

        Route::post('/{event}/media', [EventInvitationMediaController::class, 'store'])
            ->middleware('throttle:invitation-media')
            ->name('media.store');
        Route::delete('/{event}/media/{staged}', [EventInvitationMediaController::class, 'destroy'])
            ->whereNumber('staged')
            ->name('media.destroy');

        // Slice C3 — guests, groups, CSV/bulk/export/QR, tables. Static sub-paths (export,
        // import, bulk, qr-sheet.pdf) are registered before the {guest}/{table} wildcard
        // routes below them, same ordering discipline as web's routes/web.php, so a literal
        // segment is never swallowed by the wildcard. Reuses the guest-bulk-send named
        // limiter verbatim; import reuses the same inline throttle:10,1 web uses.
        Route::prefix('{event}/guests')->name('guests.')->group(function (): void {
            Route::get('/', [GuestController::class, 'index'])->name('index');
            Route::post('/', [GuestController::class, 'store'])->name('store');
            Route::get('/export', [GuestController::class, 'export'])->name('export');
            Route::get('/export-pdf', [GuestController::class, 'exportPdf'])->name('export-pdf');
            Route::get('/qr-sheet.pdf', [GuestController::class, 'qrSheet'])->name('qr-sheet');
            Route::get('/import/template', [GuestImportController::class, 'downloadTemplate'])->name('import.template');
            Route::post('/import', [GuestImportController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('import.store');
            Route::post('/bulk', [GuestBulkActionController::class, 'store'])
                ->middleware('throttle:guest-bulk-send')
                ->name('bulk');
            Route::patch('/{guest}', [GuestController::class, 'update'])->name('update');
            Route::delete('/{guest}', [GuestController::class, 'destroy'])->name('destroy');
            Route::patch('/{guest}/invitation-sent', [GuestController::class, 'markInvitationSent'])->name('mark-sent');
        });

        Route::prefix('{event}/guest-groups')->name('guest-groups.')->group(function (): void {
            Route::get('/', [GuestGroupController::class, 'index'])->name('index');
            Route::post('/', [GuestGroupController::class, 'store'])->name('store');
            Route::patch('/{guest_group}', [GuestGroupController::class, 'update'])->name('update');
            Route::delete('/{guest_group}', [GuestGroupController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('{event}/tables')->name('tables.')->group(function (): void {
            Route::get('/', [EventTableController::class, 'index'])->name('index');
            Route::post('/', [EventTableController::class, 'store'])->name('store');
            Route::get('/qr-sheet.pdf', [EventTableController::class, 'qrSheet'])->name('qr-sheet');
            Route::patch('/{table}', [EventTableController::class, 'update'])->name('update');
            Route::delete('/{table}', [EventTableController::class, 'destroy'])->name('destroy');
        });

        // Slice D — check-in, staff, ticketing ops. Needed for Android Phase 3
        // (plans/android-implementation.md §0.5). CheckInController/TicketCheckInController
        // already return JSON on the web side (their scanner page calls them via fetch()
        // with session auth) — EventCheckInController/EventTicketCheckInController below are
        // close to a verbatim port, just swapping the guard. Literal segments
        // (lookup/guest/{guest}/links/ticket/{ticket}) are registered before the trailing
        // {token} wildcard, same ordering discipline routes/web.php uses.
        Route::prefix('{event}/checkin')->name('checkin.')->group(function (): void {
            Route::get('/lookup', [EventCheckInController::class, 'lookup'])->name('lookup');
            Route::post('/guest/{guest}', [EventCheckInController::class, 'confirmGuest'])->name('confirm-guest');

            // Staff scanner links — generate/revoke a no-login share URL. Applies to both
            // invitation and ticketed events (EventStaffLink is generic); do NOT App-Link
            // the returned scanner_url (plans/android-app.md §5.2) — it's meant to open in
            // a plain browser for someone without this app or an account. Registered
            // before the trailing {token} wildcard below — POST /checkin/links would
            // otherwise be swallowed by POST /checkin/{token} (token="links").
            Route::get('/links', [EventStaffLinkController::class, 'index'])->name('links.index');
            Route::post('/links', [EventStaffLinkController::class, 'store'])->name('links.store');
            Route::delete('/links/{link}', [EventStaffLinkController::class, 'destroy'])->name('links.destroy');

            Route::post('/{token}', [EventCheckInController::class, 'confirmToken'])->name('confirm-token');
        });

        // Staff accounts (Phase 18 twin) — ticketed events only, owner-only. Distinct from
        // the staff *links* above: an EventStaff row is a real account with a role
        // (manager/checkin), never conflate the two in the app's UI either.
        Route::prefix('{event}/staff')->name('staff.')->group(function (): void {
            Route::get('/', [EventStaffController::class, 'index'])->name('index');
            Route::post('/', [EventStaffController::class, 'store'])->name('store');
            Route::patch('/{eventStaff}', [EventStaffController::class, 'update'])->name('update');
            Route::post('/{eventStaff}/resend', [EventStaffController::class, 'resend'])->name('resend');
            Route::delete('/{eventStaff}', [EventStaffController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('{event}/ticket-types')->name('ticket-types.')->group(function (): void {
            Route::get('/', [EventTicketTypeController::class, 'index'])->name('index');
            Route::post('/', [EventTicketTypeController::class, 'store'])->name('store');
            Route::patch('/{ticketType}', [EventTicketTypeController::class, 'update'])->name('update');
            Route::delete('/{ticketType}', [EventTicketTypeController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('{event}/ticketing')->name('ticketing.')->group(function (): void {
            Route::patch('/', [EventTicketingController::class, 'update'])->name('update');
            Route::post('/submit', [EventTicketingController::class, 'submit'])->name('submit');
        });

        // Host ticket dashboard/management/revenue + ticket check-in. Static segments
        // (overview/export/revenue/payouts/checkin/*) registered before the {ticket}
        // wildcard actions, same order as routes/web.php's Ticketing section.
        Route::prefix('{event}/tickets')->name('tickets.')->group(function (): void {
            Route::get('/overview', [EventTicketDashboardController::class, 'overview'])->name('overview');
            Route::get('/revenue', [EventTicketRevenueController::class, 'revenue'])->name('revenue');
            Route::get('/payouts', [EventTicketRevenueController::class, 'payouts'])->name('payouts');
            Route::get('/export', [EventTicketManagementController::class, 'export'])->name('export');

            Route::get('/checkin/lookup', [EventTicketCheckInController::class, 'lookup'])->name('checkin.lookup');
            Route::post('/checkin/ticket/{ticket}', [EventTicketCheckInController::class, 'confirmTicket'])->name('checkin.confirm-ticket');
            Route::post('/checkin/{token}', [EventTicketCheckInController::class, 'confirmToken'])->name('checkin.confirm-token');

            Route::get('/', [EventTicketManagementController::class, 'index'])->name('index');
            Route::post('/{ticket}/resend', [EventTicketManagementController::class, 'resend'])->name('resend');
            Route::post('/{ticket}/reissue', [EventTicketManagementController::class, 'reissue'])->name('reissue');
            Route::post('/{ticket}/cancel', [EventTicketManagementController::class, 'cancel'])->name('cancel');
            Route::post('/{ticket}/confirm-checkin', [EventTicketManagementController::class, 'confirmCheckIn'])->name('confirm-checkin');
        });
    });
});
