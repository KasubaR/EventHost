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
use App\Http\Controllers\EventStaffLinkController;
use App\Http\Controllers\EventTableController;
use App\Http\Controllers\GuestBulkActionController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestGroupController;
use App\Http\Controllers\GuestImportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MapLinkController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PublicCheckInController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RsvpController;
use App\Http\Controllers\Settings\AccountController as SettingsAccountController;
use App\Http\Controllers\Settings\NotificationController as SettingsNotificationController;
use App\Http\Controllers\Settings\ProfileController as SettingsProfileController;
use App\Http\Controllers\Settings\SecurityController as SettingsSecurityController;
use App\Http\Controllers\TableUploadController;
use App\Http\Controllers\TemplateLibraryController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

$lencoWebhookPath = trim((string) config('services.lenco.webhook_path'), '/') ?: 'lenco/webhook';

Route::post($lencoWebhookPath, [PaymentController::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lenco.webhook');

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/about', function () {
    return view('about');
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

Route::get('/e/{slug}/table/{code}', [TableUploadController::class, 'show'])->name('table.upload.show');
Route::post('/e/{slug}/table/{code}/photos', [TableUploadController::class, 'store'])
    ->middleware('throttle:table-upload')
    ->name('table.upload.store');

Route::get('/e/{slug}/gallery', [EventGalleryController::class, 'show'])->name('event.gallery.show');
Route::get('/e/{slug}/gallery/feed', [EventGalleryController::class, 'feed'])->name('event.gallery.feed');

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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

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

    Route::get('/events/{event}/checkin/lookup', [CheckInController::class, 'lookup'])
        ->name('events.checkin.lookup');
    Route::post('/events/{event}/checkin/guest/{guest}', [CheckInController::class, 'confirmGuest'])
        ->name('events.checkin.confirm-guest');
    Route::post('/events/{event}/checkin/{token}', [CheckInController::class, 'confirmToken'])
        ->name('events.checkin.confirm-token');
    Route::get('/events/{event}/checkin', [CheckInController::class, 'scan'])
        ->name('events.checkin.scan');

    Route::patch('/events/{event}/photos/{photo}', [EventPhotoController::class, 'update'])
        ->name('events.photos.update');
    Route::delete('/events/{event}/photos/{photo}', [EventPhotoController::class, 'destroy'])
        ->name('events.photos.destroy');
    Route::get('/events/{event}/photos', [EventPhotoController::class, 'index'])
        ->name('events.photos.index');

    Route::patch('/events/{event}/publish', [EventController::class, 'publish'])->name('events.publish');
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

        Route::get('/profile', [SettingsProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [SettingsProfileController::class, 'update'])->name('profile.update');

        Route::get('/security', [SettingsSecurityController::class, 'edit'])->name('security.edit');

        Route::get('/notifications', [SettingsNotificationController::class, 'edit'])->name('notifications.edit');
        Route::patch('/notifications', [SettingsNotificationController::class, 'update'])->name('notifications.update');

        Route::get('/account', [SettingsAccountController::class, 'edit'])->name('account.edit');
        Route::delete('/account', [SettingsAccountController::class, 'destroy'])->name('account.destroy');
    });

    // Kept for bookmarks and any older link that still points at /profile.
    Route::redirect('/profile', '/settings/profile', 301)->name('profile.edit');
});

require __DIR__.'/auth.php';
