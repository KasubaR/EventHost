<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventChooseTemplateController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventInvitationDesignController;
use App\Http\Controllers\GuestBulkActionController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestGroupController;
use App\Http\Controllers\GuestImportController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\RsvpController;
use App\Http\Controllers\TemplateLibraryController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

$lencoWebhookPath = trim((string) config('services.lenco.webhook_path'), '/') ?: 'lenco/webhook';

Route::post($lencoWebhookPath, [PaymentController::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('lenco.webhook');

Route::get('/', function () {
    return view('home');
});

Route::get('/about', function () {
    return view('about');
})->name('about');

Route::get('/contact', [ContactController::class, 'show'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store')->middleware('throttle:5,1');

Route::get('/e/{slug}', [PublicEventController::class, 'show'])->name('events.public');
Route::get('/e/{slug}/calendar.ics', [PublicEventController::class, 'ics'])->name('events.public.ics');

Route::get('/rsvp/thanks', [RsvpController::class, 'thanks'])->name('rsvp.thanks');
Route::get('/rsvp/{token}', [RsvpController::class, 'showByToken'])->name('rsvp.token.show');
Route::get('/e/{slug}/rsvp', [RsvpController::class, 'showOpen'])->name('rsvp.open.show');

Route::middleware('throttle:rsvp-submit')->group(function () {
    Route::post('/rsvp/{token}', [RsvpController::class, 'storeByToken'])->name('rsvp.token.store');
    Route::post('/e/{slug}/rsvp', [RsvpController::class, 'storeOpen'])->name('rsvp.open.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/templates', [TemplateLibraryController::class, 'index'])->name('templates.index');
    Route::get('/templates/{invitation_template}/preview', [TemplateLibraryController::class, 'preview'])->name('templates.preview');

    Route::get('/events/{event}/choose-template', [EventChooseTemplateController::class, 'show'])->name('events.choose-template');
    Route::patch('/events/{event}/choose-template', [EventChooseTemplateController::class, 'update'])->name('events.choose-template.update');

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

    Route::resource('events.guests', GuestController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
        ->scoped([
            'guest' => 'guests',
        ]);

    Route::patch('/events/{event}/publish', [EventController::class, 'publish'])->name('events.publish');
    Route::patch('/events/{event}/invitation-design', [EventInvitationDesignController::class, 'update'])
        ->middleware('throttle:invitation-design')
        ->name('events.invitation-design.update');
    Route::resource('events', EventController::class)->except('store');
    Route::post('/events', [EventController::class, 'store'])->name('events.store')->middleware('throttle:10,1');

    Route::get('/billing', [PaymentController::class, 'show'])->name('billing.show');
    Route::post('/payment/initiate', [PaymentController::class, 'initiate'])->name('payment.initiate');
    Route::get('/payment/verify/{transactionId}', [PaymentController::class, 'verify'])
        ->where('transactionId', '[A-Za-z0-9_\-]{1,64}')
        ->name('payment.verify');
    Route::get('/payment/verify-ref/{reference}', [PaymentController::class, 'verifyByReference'])
        ->where('reference', '[A-Za-z0-9_\-]{1,128}')
        ->name('payment.verify.ref');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
