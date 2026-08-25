<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CustomQuoteController as AdminCustomQuoteController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventController as AdminEventController;
use App\Http\Controllers\Admin\FaqController as AdminFaqController;
use App\Http\Controllers\Admin\GuestController as AdminGuestController;
use App\Http\Controllers\Admin\InvitationTemplateController as AdminInvitationTemplateController;
use App\Http\Controllers\Admin\NotificationLogController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\RsvpController as AdminRsvpController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TicketingController as AdminTicketingController;
use App\Http\Controllers\Admin\TicketRevenueController;
use App\Http\Controllers\Admin\TicketTypeController as AdminTicketTypeController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use Illuminate\Support\Facades\Route;

// Admin guest routes (unauthenticated)
Route::prefix('admin')->name('admin.')->middleware('guest:admin')->group(function (): void {
    Route::get('/login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'store'])->middleware('throttle:admin-login');
});

// Admin logout
Route::post('admin/logout', [AdminAuthController::class, 'destroy'])
    ->name('admin.logout')
    ->middleware(['admin.auth']);

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['admin.auth', 'role:super_admin|admin|support,admin'])
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('admin.dashboard'));

        Route::middleware('permission:analytics.view,admin')->group(function (): void {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
        });

        Route::middleware('permission:users.view,admin')->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        });

        Route::middleware(['permission:users.manage_status,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
            Route::post('/users/{user}/credits', [AdminUserController::class, 'addCredits'])->name('users.add-credits');
            Route::patch('/users/{user}/tier', [AdminUserController::class, 'updateTier'])->name('users.update-tier');
            Route::patch('/users/{user}/email', [AdminUserController::class, 'updateEmail'])->name('users.update-email');
            Route::post('/users/{user}/custom-quote', [AdminCustomQuoteController::class, 'store'])->name('users.custom-quote.store');
            Route::patch('/users/{user}/custom-quote/{customQuote}', [AdminCustomQuoteController::class, 'update'])->name('users.custom-quote.update');
            Route::delete('/users/{user}/custom-quote/{customQuote}', [AdminCustomQuoteController::class, 'destroy'])->name('users.custom-quote.destroy');
        });

        Route::middleware(['permission:users.password_reset,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::post('/users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->name('users.password-reset');
        });

        Route::middleware(['permission:users.delete,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
        });

        Route::middleware('permission:events.view,admin')->group(function (): void {
            Route::get('/events', [AdminEventController::class, 'index'])->name('events.index');
            Route::get('/events/{event}', [AdminEventController::class, 'show'])
                ->withTrashed()
                ->name('events.show');
        });

        Route::middleware(['permission:events.publish_toggle,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::patch('/events/{event}/publish', [AdminEventController::class, 'updatePublish'])->name('events.publish');
            Route::patch('/events/{event}/pause', [AdminEventController::class, 'pause'])->name('events.pause');
            Route::patch('/events/{event}/resume', [AdminEventController::class, 'resume'])->name('events.resume');
            Route::patch('/events/{event}/cancel', [AdminEventController::class, 'cancel'])->name('events.cancel');
            Route::patch('/events/{event}/uncancel', [AdminEventController::class, 'uncancel'])->name('events.uncancel');
        });

        Route::middleware(['permission:events.delete,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::delete('/events/{event}', [AdminEventController::class, 'destroy'])->name('events.destroy');
            Route::post('/events/{event}/restore', [AdminEventController::class, 'restore'])
                ->withTrashed()
                ->name('events.restore');
        });

        Route::middleware('permission:guests.view,admin')->group(function (): void {
            Route::get('/events/{event}/guests', [AdminGuestController::class, 'index'])
                ->withTrashed()
                ->name('events.guests');
        });

        Route::middleware('permission:rsvps.view,admin')->group(function (): void {
            Route::get('/events/{event}/rsvps', [AdminRsvpController::class, 'index'])
                ->withTrashed()
                ->name('events.rsvps');
        });

        Route::middleware('permission:notifications.view,admin')->group(function (): void {
            Route::get('/notifications', [NotificationLogController::class, 'index'])->name('notifications.index');
        });

        Route::middleware('permission:payments.view,admin')->group(function (): void {
            Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
            Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');
        });

        Route::middleware('permission:reports.view,admin')->group(function (): void {
            Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/{report}', [AdminReportController::class, 'show'])->name('reports.show');
        });

        Route::middleware(['permission:reports.manage,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::patch('/reports/{report}', [AdminReportController::class, 'update'])->name('reports.update');
        });

        Route::middleware('permission:templates.manage,admin')->group(function (): void {
            Route::get('/templates', [AdminInvitationTemplateController::class, 'index'])->name('templates.index');
        });

        Route::middleware(['permission:templates.manage,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::patch('/templates/{invitation_template}', [AdminInvitationTemplateController::class, 'update'])->name('templates.update');
            Route::delete('/templates/{invitation_template}/image', [AdminInvitationTemplateController::class, 'destroyImage'])->name('templates.image.destroy');
        });

        Route::middleware('permission:faqs.manage,admin')->group(function (): void {
            Route::get('/faqs', [AdminFaqController::class, 'index'])->name('faqs.index');
        });

        Route::middleware(['permission:faqs.manage,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::post('/faqs', [AdminFaqController::class, 'store'])->name('faqs.store');
            Route::patch('/faqs/{faq}', [AdminFaqController::class, 'update'])->name('faqs.update');
            Route::delete('/faqs/{faq}', [AdminFaqController::class, 'destroy'])->name('faqs.destroy');
        });

        Route::middleware('permission:reviews.manage,admin')->group(function (): void {
            Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        });

        Route::middleware(['permission:ticketing.approve,admin'])->group(function (): void {
            // Must precede /ticketing/{event} so "create" is not treated as an event id.
            Route::get('/ticketing/create', [AdminTicketingController::class, 'create'])->name('ticketing.create');
        });

        Route::middleware('permission:ticketing.view,admin')->group(function (): void {
            // Registered before /ticketing/{event} below — otherwise that
            // wildcard would swallow /ticketing/revenue by matching "revenue"
            // as {event}, same reasoning as /checkin/tickets/{staffToken}
            // needing to precede /checkin/{staffToken} in routes/web.php.
            Route::get('/ticketing/revenue', [TicketRevenueController::class, 'index'])->name('ticketing.revenue.index');
            Route::get('/ticketing/revenue/{event}', [TicketRevenueController::class, 'show'])->name('ticketing.revenue.show');

            // Same early-registration reasoning as /ticketing/revenue above —
            // must precede /ticketing/{event}.
            Route::get('/ticketing/reconciliation', [ReconciliationController::class, 'index'])->name('ticketing.reconciliation.index');
            Route::get('/ticketing/reconciliation/{order}', [ReconciliationController::class, 'order'])->name('ticketing.reconciliation.order');

            Route::get('/ticketing', [AdminTicketingController::class, 'index'])->name('ticketing.index');
            Route::get('/ticketing/{event}', [AdminTicketingController::class, 'show'])->name('ticketing.show');
        });

        Route::middleware(['permission:ticketing.approve,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::post('/ticketing/create', [AdminTicketingController::class, 'store'])->name('ticketing.store');

            Route::post('/ticketing/{event}/hero', [AdminTicketingController::class, 'updateHero'])->name('ticketing.hero');
            Route::post('/ticketing/{event}/approve', [AdminTicketingController::class, 'approve'])->name('ticketing.approve');
            Route::post('/ticketing/{event}/reject', [AdminTicketingController::class, 'reject'])->name('ticketing.reject');
            Route::patch('/ticketing/{event}/terms', [AdminTicketingController::class, 'updateTerms'])->name('ticketing.terms');
            Route::patch('/ticketing/{event}/commission', [AdminTicketingController::class, 'updateCommission'])->name('ticketing.commission');

            Route::get('/ticketing/{event}/ticket-types/create', [AdminTicketTypeController::class, 'create'])->name('ticketing.ticket-types.create');
            Route::post('/ticketing/{event}/ticket-types', [AdminTicketTypeController::class, 'store'])->name('ticketing.ticket-types.store');
            Route::get('/ticketing/{event}/ticket-types/{ticketType}/edit', [AdminTicketTypeController::class, 'edit'])->name('ticketing.ticket-types.edit');
            Route::patch('/ticketing/{event}/ticket-types/{ticketType}', [AdminTicketTypeController::class, 'update'])->name('ticketing.ticket-types.update');
            Route::delete('/ticketing/{event}/ticket-types/{ticketType}', [AdminTicketTypeController::class, 'destroy'])->name('ticketing.ticket-types.destroy');
        });

        Route::middleware(['permission:ticketing.payouts.manage,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::post('/ticketing/revenue/{event}/payouts', [TicketRevenueController::class, 'storePayout'])->name('ticketing.revenue.payouts.store');
        });

        Route::middleware(['permission:ticketing.reconcile,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::post('/ticketing/reconciliation/{order}/reverify', [ReconciliationController::class, 'reverify'])->name('ticketing.reconciliation.reverify');
        });

        Route::middleware(['permission:reviews.manage,admin', 'throttle:admin-mutations'])->group(function (): void {
            Route::post('/reviews', [AdminReviewController::class, 'store'])->name('reviews.store');
            Route::patch('/reviews/{review}', [AdminReviewController::class, 'update'])->name('reviews.update');
            Route::delete('/reviews/{review}', [AdminReviewController::class, 'destroy'])->name('reviews.destroy');
            Route::delete('/reviews/{review}/poster', [AdminReviewController::class, 'destroyPoster'])->name('reviews.poster.destroy');
        });

        Route::middleware('permission:settings.manage,admin')->group(function (): void {
            Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
            Route::patch('/settings', [SettingController::class, 'update'])->middleware('throttle:admin-mutations')->name('settings.update');
        });
    });
