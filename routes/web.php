<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventChooseTemplateController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventInvitationDesignController;
use App\Http\Controllers\GuestBulkActionController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestGroupController;
use App\Http\Controllers\GuestImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\RsvpController;
use App\Http\Controllers\TemplateLibraryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
