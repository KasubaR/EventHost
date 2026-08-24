<?php

use App\Http\Controllers\CheckInController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventChooseTemplateController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventGalleryController;
use App\Http\Controllers\EventInvitationDesignController;
use App\Http\Controllers\EventInvitationMediaController;
use App\Http\Controllers\EventPhotoController;
use App\Http\Controllers\EventPreviewController;
use App\Http\Controllers\EventStaffController;
use App\Http\Controllers\EventStaffInvitationController;
use App\Http\Controllers\EventStaffLinkController;
use App\Http\Controllers\EventTableController;
use App\Http\Controllers\EventTicketCheckoutController;
use App\Http\Controllers\EventTicketDashboardController;
use App\Http\Controllers\EventTicketingController;
use App\Http\Controllers\EventTicketManagementController;
use App\Http\Controllers\EventTicketPurchaseController;
use App\Http\Controllers\EventTicketRevenueController;
use App\Http\Controllers\EventTicketTypeController;
use App\Http\Controllers\GuestBulkActionController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestGroupController;
use App\Http\Controllers\GuestImportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MapLinkController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PublicCheckInController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\PublicTicketCheckInController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RsvpController;
use App\Http\Controllers\Settings\AccountController as SettingsAccountController;
use App\Http\Controllers\Settings\NotificationController as SettingsNotificationController;
use App\Http\Controllers\Settings\ProfileController as SettingsProfileController;
use App\Http\Controllers\Settings\SecurityController as SettingsSecurityController;
use App\Http\Controllers\TableUploadController;
use App\Http\Controllers\TemplateLibraryController;
use App\Http\Controllers\TicketCheckInController;
use App\Http\Controllers\TicketController;
use App\Models\InvitationTemplate;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

$lencoWebhookPath = trim((string) config('services.lenco.webhook_path'), '/') ?: 'lenco/webhook';

Route::post($lencoWebhookPath, [PaymentController::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lenco.webhook');

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/about', function () {
    return view('about', [
        'activeTemplateCount' => InvitationTemplate::activeCount(),
    ]);
})->name('about');

// Static policy pages. Linked from the footer and from the sign-in / sign-up
// consent lines, which is why they are public and outside every middleware group.
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/cookies', 'legal.cookies')->name('legal.cookies');

Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store')->middleware('throttle:5,1');

// Public browse listing. Note /events is taken by the authenticated events.index.
Route::get('/discover', [PublicEventController::class, 'index'])->name('events.discover');

// Public so the homepage's featured templates can be viewed before signing up.
// It renders an in-memory sample event only — no stored user or event data.
// The library listing at /templates stays behind auth.
Route::get('/templates/{invitation_template}/preview', [TemplateLibraryController::class, 'preview'])
    ->name('templates.preview');

Route::get('/e/{slug}', [PublicEventController::class, 'show'])->name('events.public');
Route::get('/e/{slug}/calendar.ics', [PublicEventController::class, 'ics'])->name('events.public.ics');

// Public ticket buy flow — no login, same posture as the RSVP flow below.
// See plans/ticketing.md §5.
Route::get('/e/{slug}/tickets', [EventTicketPurchaseController::class, 'show'])->name('events.public.tickets');
Route::post('/e/{slug}/tickets/hold', [EventTicketPurchaseController::class, 'hold'])
    ->middleware('throttle:ticket-hold')
    ->name('events.public.tickets.hold');
Route::get('/e/{slug}/tickets/checkout', [EventTicketCheckoutController::class, 'show'])
    ->name('events.public.tickets.checkout');
Route::post('/e/{slug}/tickets/checkout', [EventTicketCheckoutController::class, 'store'])
    ->middleware('throttle:ticket-checkout')
    ->name('events.public.tickets.checkout.store');

Route::get('/tickets/orders/{orderReference}', [EventTicketCheckoutController::class, 'status'])
    ->where('orderReference', '[A-Za-z0-9_\-]{1,128}')
    ->name('ticket.orders.show');
Route::get('/tickets/orders/{orderReference}/verify', [EventTicketCheckoutController::class, 'verify'])
    ->middleware('throttle:ticket-verify')
    ->where('orderReference', '[A-Za-z0-9_\-]{1,128}')
    ->name('ticket.orders.verify');

// Buyer's secure ticket page — token in the URL is the only guard, no login,
// same trust model as rsvp.token.show.
Route::get('/t/{token}', [TicketController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->name('tickets.show');
Route::get('/t/{token}/qr.svg', [TicketController::class, 'qr'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->name('tickets.qr');
Route::get('/t/{token}/download', [TicketController::class, 'download'])
    ->middleware('throttle:ticket-download')
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->name('tickets.download');

Route::get('/e/{slug}/table/{code}', [TableUploadController::class, 'show'])->name('table.upload.show');
Route::post('/e/{slug}/table/{code}/photos', [TableUploadController::class, 'store'])
    ->middleware('throttle:table-upload')
    ->name('table.upload.store');

Route::get('/e/{slug}/gallery', [EventGalleryController::class, 'show'])->name('event.gallery.show');
Route::get('/e/{slug}/gallery/feed', [EventGalleryController::class, 'feed'])->name('event.gallery.feed');

// Ticket staff-link scanner (Phase 17) — registered before the guest catch-all
// below, since /checkin/{staffToken} would otherwise swallow /checkin/tickets/…
// by matching "tickets" as staffToken. Twin of PublicCheckInController, one
// path segment over so both can live under /checkin without colliding.
Route::get('/checkin/tickets/{staffToken}', [PublicTicketCheckInController::class, 'scan'])->name('tickets.checkin.public.scan');
Route::middleware('throttle:staff-checkin')->group(function () {
    Route::get('/checkin/tickets/{staffToken}/lookup', [PublicTicketCheckInController::class, 'lookup'])->name('tickets.checkin.public.lookup');
    Route::post('/checkin/tickets/{staffToken}/ticket/{ticket}', [PublicTicketCheckInController::class, 'confirmTicket'])->name('tickets.checkin.public.confirm-ticket');
    Route::post('/checkin/tickets/{staffToken}/{token}', [PublicTicketCheckInController::class, 'confirmToken'])->name('tickets.checkin.public.confirm-token');
});

Route::get('/checkin/{staffToken}', [PublicCheckInController::class, 'scan'])->name('checkin.public.scan');
// Guest badges encode this path (see Guest::checkInQrUrl()). Phone cameras GET
// it; the scanner page POSTs it. GET must not check anyone in.
Route::get('/events/{event}/checkin/{token}', [CheckInController::class, 'openFromCamera'])
    ->where('token', '[A-Za-z0-9_\-]{16,128}')
    ->name('events.checkin.qr-open');
Route::middleware('throttle:staff-checkin')->group(function () {
    Route::get('/checkin/{staffToken}/lookup', [PublicCheckInController::class, 'lookup'])->name('checkin.public.lookup');
    Route::post('/checkin/{staffToken}/guest/{guest}', [PublicCheckInController::class, 'confirmGuest'])->name('checkin.public.confirm-guest');
    Route::post('/checkin/{staffToken}/{token}', [PublicCheckInController::class, 'confirmToken'])->name('checkin.public.confirm-token');
});

Route::get('/rsvp/thanks', [RsvpController::class, 'thanks'])->name('rsvp.thanks');
// Refreshable/bookmarkable confirmation page for token guests — re-queries fresh
// event/guest/RSVP data on every load instead of relying on a one-shot session
// flash. Open (no-token) RSVPs have no persistent identifier safe to key a URL
// off of, so they still land on the flash-only /rsvp/thanks above.
Route::get('/rsvp/{token}/thanks', [RsvpController::class, 'thanksByToken'])->name('rsvp.token.thanks');
Route::get('/rsvp/{token}', [RsvpController::class, 'showByToken'])->name('rsvp.token.show');
// Same trust model as the line above: the token in the URL is the only guard, no
// login, no throttle — a guest reopens this repeatedly to show their entry pass.
Route::get('/rsvp/{token}/entry-pass.svg', [RsvpController::class, 'entryPassQr'])->name('rsvp.token.entry-pass');
Route::get('/e/{slug}/rsvp', [RsvpController::class, 'showOpen'])->name('rsvp.open.show');

Route::middleware('throttle:rsvp-submit')->group(function () {
    Route::post('/rsvp/{token}', [RsvpController::class, 'storeByToken'])->name('rsvp.token.store');
    Route::post('/e/{slug}/rsvp', [RsvpController::class, 'storeOpen'])->name('rsvp.open.store');
});

// Staff invite accept flow (Phase 18) — twin paths depending on whether the
// invited email already has an account. Same trust model as the token routes
// above: the token in the URL is the bearer credential, no login required to
// view. See EventStaffInvitationController.
Route::get('/staff/invitations/{token}', [EventStaffInvitationController::class, 'show'])->name('staff-invitations.show');
Route::post('/staff/invitations/{token}', [EventStaffInvitationController::class, 'store'])->name('staff-invitations.store');

Route::middleware(['auth', 'account.active', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Existing-account branch of the staff accept flow — hitting this while
    // logged out gets Laravel's normal "log in, then come back" intended-URL
    // redirect for free, which is the whole reason this route lives in the
    // auth group instead of the public pair above.
    Route::get('/staff/invitations/{token}/confirm', [EventStaffInvitationController::class, 'confirm'])
        ->name('staff-invitations.confirm');

    Route::get('/templates', [TemplateLibraryController::class, 'index'])->name('templates.index');

    Route::get('/events/{event}/choose-template', [EventChooseTemplateController::class, 'show'])->name('events.choose-template');
    Route::patch('/events/{event}/choose-template', [EventChooseTemplateController::class, 'update'])->name('events.choose-template.update');

    // Host-only view of the real invitation, gated on ownership rather than
    // is_published/is_public — lets a host preview a draft or a private event
    // that the public /e/{slug} route would otherwise 403 on.
    Route::get('/events/{event}/preview', [EventPreviewController::class, 'show'])->name('events.preview');

    Route::get('/events/{event}/guests/import/template', [GuestImportController::class, 'downloadTemplate'])
        ->name('events.guests.import.template');
    Route::get('/events/{event}/guests/import', [GuestImportController::class, 'create'])
        ->name('events.guests.import.create');
    Route::post('/events/{event}/guests/import', [GuestImportController::class, 'store'])
        ->name('events.guests.import.store')
        ->middleware('throttle:10,1');

    Route::post('/events/{event}/guests/bulk', [GuestBulkActionController::class, 'store'])
        ->middleware('throttle:guest-bulk-send')
        ->name('events.guests.bulk');

    Route::resource('events.guest-groups', GuestGroupController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::get('/events/{event}/guests/export', [GuestController::class, 'export'])
        ->name('events.guests.export');

    Route::get('/events/{event}/guests/export-pdf', [GuestController::class, 'exportPdf'])
        ->name('events.guests.export-pdf');

    Route::patch('/events/{event}/guests/{guest}/invitation-sent', [GuestController::class, 'markInvitationSent'])
        ->name('events.guests.mark-invitation-sent');

    Route::get('/events/{event}/guests/{guest}/qr.svg', [GuestController::class, 'qr'])
        ->name('events.guests.qr');

    Route::get('/events/{event}/guests/qr-sheet.pdf', [GuestController::class, 'qrSheet'])
        ->name('events.guests.qr-sheet');

    // scoped() with no fields: {guest} is looked up via Event::guests()->where('id', ...) —
    // its default is the related model's own route key ('id'). Passing scoped(['guest' =>
    // 'guests']) (as this line used to) tells Laravel to match a column literally named
    // "guests" instead, which doesn't exist — every edit/update/destroy 404'd silently.
    Route::resource('events.guests', GuestController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
        ->scoped();

    Route::resource('events.ticket-types', EventTicketTypeController::class)
        ->parameters(['ticket-types' => 'ticketType'])
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
        ->scoped();
    Route::patch('/events/{event}/ticketing', [EventTicketingController::class, 'update'])
        ->name('events.ticketing.update');
    Route::post('/events/{event}/ticketing/submit', [EventTicketingController::class, 'submit'])
        ->name('events.ticketing.submit');

    // Host "Ticketing" section (Phase 15/16) — Overview dashboard + the
    // individual-tickets table. Settings stays on events.ticket-types.* above;
    // Check-in stays on events.tickets.checkin.* below.
    Route::get('/events/{event}/tickets/overview', [EventTicketDashboardController::class, 'overview'])
        ->name('events.tickets.overview');
    // Revenue/Payouts (Phase 23) — read-only for the host; only an admin can
    // record a payout, see admin.ticketing.revenue.payouts.store.
    Route::get('/events/{event}/tickets/revenue', [EventTicketRevenueController::class, 'revenue'])
        ->name('events.tickets.revenue');
    Route::get('/events/{event}/tickets/payouts', [EventTicketRevenueController::class, 'payouts'])
        ->name('events.tickets.payouts');
    Route::get('/events/{event}/tickets', [EventTicketManagementController::class, 'index'])
        ->name('events.tickets.index');
    Route::post('/events/{event}/tickets/{ticket}/resend', [EventTicketManagementController::class, 'resend'])
        ->middleware('throttle:ticket-resend')
        ->name('events.tickets.resend');
    Route::post('/events/{event}/tickets/{ticket}/cancel', [EventTicketManagementController::class, 'cancel'])
        ->name('events.tickets.cancel');
    Route::post('/events/{event}/tickets/{ticket}/confirm-checkin', [EventTicketManagementController::class, 'confirmCheckIn'])
        ->name('events.tickets.confirm-checkin');

    Route::get('/events/{event}/tables/qr-sheet.pdf', [EventTableController::class, 'qrSheet'])
        ->name('events.tables.qr-sheet');
    Route::get('/events/{event}/tables/{table}/qr.svg', [EventTableController::class, 'qr'])
        ->name('events.tables.qr');
    Route::resource('events.tables', EventTableController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::post('/events/{event}/checkin/links', [EventStaffLinkController::class, 'store'])
        ->name('events.checkin.links.store');
    Route::delete('/events/{event}/checkin/links/{link}', [EventStaffLinkController::class, 'destroy'])
        ->name('events.checkin.links.destroy');

    // Owner-only staff accounts (Phase 18) — twin of the no-login scanner
    // links above, for people the host trusts with an actual account. See
    // plans/staff-access.md.
    Route::get('/events/{event}/staff', [EventStaffController::class, 'index'])
        ->name('events.staff.index');
    Route::post('/events/{event}/staff', [EventStaffController::class, 'store'])
        ->name('events.staff.store');
    Route::patch('/events/{event}/staff/{eventStaff}', [EventStaffController::class, 'update'])
        ->name('events.staff.update');
    Route::post('/events/{event}/staff/{eventStaff}/resend', [EventStaffController::class, 'resend'])
        ->name('events.staff.resend');
    Route::delete('/events/{event}/staff/{eventStaff}', [EventStaffController::class, 'destroy'])
        ->name('events.staff.destroy');

    Route::get('/events/{event}/checkin/lookup', [CheckInController::class, 'lookup'])
        ->name('events.checkin.lookup');
    Route::post('/events/{event}/checkin/guest/{guest}', [CheckInController::class, 'confirmGuest'])
        ->name('events.checkin.confirm-guest');
    Route::post('/events/{event}/checkin/{token}', [CheckInController::class, 'confirmToken'])
        ->name('events.checkin.confirm-token');
    Route::get('/events/{event}/checkin', [CheckInController::class, 'scan'])
        ->name('events.checkin.scan');

    Route::get('/events/{event}/tickets/checkin/lookup', [TicketCheckInController::class, 'lookup'])
        ->name('events.tickets.checkin.lookup');
    Route::post('/events/{event}/tickets/checkin/ticket/{ticket}', [TicketCheckInController::class, 'confirmTicket'])
        ->name('events.tickets.checkin.confirm-ticket');
    Route::post('/events/{event}/tickets/checkin/{token}', [TicketCheckInController::class, 'confirmToken'])
        ->name('events.tickets.checkin.confirm-token');
    Route::get('/events/{event}/tickets/checkin', [TicketCheckInController::class, 'scan'])
        ->name('events.tickets.checkin.scan');

    Route::patch('/events/{event}/photos/{photo}', [EventPhotoController::class, 'update'])
        ->name('events.photos.update');
    Route::delete('/events/{event}/photos/{photo}', [EventPhotoController::class, 'destroy'])
        ->name('events.photos.destroy');
    Route::get('/events/{event}/photos', [EventPhotoController::class, 'index'])
        ->name('events.photos.index');

    Route::patch('/events/{event}/publish', [EventController::class, 'publish'])->name('events.publish');
    Route::patch('/events/{event}/pause', [EventController::class, 'pause'])->name('events.pause');
    Route::patch('/events/{event}/resume', [EventController::class, 'resume'])->name('events.resume');
    Route::patch('/events/{event}/cancel', [EventController::class, 'cancel'])->name('events.cancel');
    Route::patch('/events/{event}/uncancel', [EventController::class, 'uncancel'])->name('events.uncancel');
    Route::post('/events/{event}/restore', [EventController::class, 'restore'])
        ->withTrashed()
        ->name('events.restore');
    Route::patch('/events/{event}/invitation-design', [EventInvitationDesignController::class, 'update'])
        ->middleware('throttle:invitation-design')
        ->name('events.invitation-design.update');

    // Uploads land here on pick, one file per request, so the save above carries
    // ids instead of binaries. Its own limiter: 'invitation-design' is sized for
    // form saves and eleven images would exhaust it.
    Route::post('/events/{event}/media', [EventInvitationMediaController::class, 'store'])
        ->middleware('throttle:invitation-media')
        ->name('events.media.stage');
    Route::delete('/events/{event}/media/{staged}', [EventInvitationMediaController::class, 'destroy'])
        ->whereNumber('staged')
        ->name('events.media.unstage');
    // Resolves a pasted Google Maps short link to coordinates for the event location picker.
    // Not scoped to {event} — the create page has no event id yet, and this carries no event data.
    Route::post('/maps/resolve-link', [MapLinkController::class, 'resolve'])
        ->middleware('throttle:map-link-resolve')
        ->name('maps.resolve-link');

    Route::resource('events', EventController::class)->except('store');
    Route::post('/events', [EventController::class, 'store'])->name('events.store')->middleware('throttle:10,1');

    Route::get('/billing', [PaymentController::class, 'show'])->name('billing.show');
    Route::post('/payment/initiate', [PaymentController::class, 'initiate'])
        ->middleware('throttle:payment-initiate')
        ->name('payment.initiate');
    Route::get('/payment/verify/{transactionId}', [PaymentController::class, 'verify'])
        ->where('transactionId', '[A-Za-z0-9_\-]{1,64}')
        ->name('payment.verify');
    Route::get('/payment/verify-ref/{reference}', [PaymentController::class, 'verifyByReference'])
        ->where('reference', '[A-Za-z0-9_\-]{1,128}')
        ->name('payment.verify.ref');

    Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::post('/reviews', [ReviewController::class, 'store'])
        ->name('reviews.store')
        ->middleware('throttle:10,1');
    Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::redirect('/', '/settings/profile')->name('index');

        Route::get('/security', [SettingsSecurityController::class, 'edit'])->name('security.edit');

        Route::get('/notifications', [SettingsNotificationController::class, 'edit'])->name('notifications.edit');
        Route::patch('/notifications', [SettingsNotificationController::class, 'update'])->name('notifications.update');

        Route::get('/account', [SettingsAccountController::class, 'edit'])->name('account.edit');
        Route::delete('/account', [SettingsAccountController::class, 'destroy'])->name('account.destroy');
    });

    // Kept for bookmarks and any older link that still points at /profile.
    Route::redirect('/profile', '/settings/profile', 301)->name('profile.edit');
});

// Deliberately outside the `verified` gate above: ProfileService nulls
// email_verified_at the moment the email changes, so a user who mistypes
// the new address (or enters one they don't control) would otherwise lose
// access to the only page that can fix or revert it — a self-inflicted,
// unrecoverable lockout. auth + account.active still apply.
Route::middleware(['auth', 'account.active'])->prefix('settings')->name('settings.')->group(function (): void {
    Route::get('/profile', [SettingsProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [SettingsProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/photo', [SettingsProfileController::class, 'destroyPhoto'])->name('profile.photo.destroy');
});

require __DIR__.'/auth.php';
