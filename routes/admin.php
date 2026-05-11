<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventController as AdminEventController;
use App\Http\Controllers\Admin\GuestController as AdminGuestController;
use App\Http\Controllers\Admin\NotificationLogController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\RsvpController as AdminRsvpController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:super_admin|admin|support'])
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('admin.dashboard'));

        Route::middleware('permission:analytics.view')->group(function (): void {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
        });

        Route::middleware('permission:users.view')->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        });

        Route::middleware(['permission:users.manage_status', 'throttle:admin-mutations'])->group(function (): void {
            Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
        });

        Route::middleware(['permission:users.password_reset', 'throttle:admin-mutations'])->group(function (): void {
            Route::post('/users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->name('users.password-reset');
        });

        Route::middleware(['permission:users.delete', 'throttle:admin-mutations'])->group(function (): void {
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
        });

        Route::middleware('permission:events.view')->group(function (): void {
            Route::get('/events', [AdminEventController::class, 'index'])->name('events.index');
            Route::get('/events/{event}', [AdminEventController::class, 'show'])->name('events.show');
        });

        Route::middleware(['permission:events.publish_toggle', 'throttle:admin-mutations'])->group(function (): void {
            Route::patch('/events/{event}/publish', [AdminEventController::class, 'updatePublish'])->name('events.publish');
        });

        Route::middleware(['permission:events.delete', 'throttle:admin-mutations'])->group(function (): void {
            Route::delete('/events/{event}', [AdminEventController::class, 'destroy'])->name('events.destroy');
        });

        Route::middleware('permission:guests.view')->group(function (): void {
            Route::get('/guests', [AdminGuestController::class, 'index'])->name('guests.index');
        });

        Route::middleware('permission:rsvps.view')->group(function (): void {
            Route::get('/rsvps', [AdminRsvpController::class, 'index'])->name('rsvps.index');
        });

        Route::middleware('permission:notifications.view')->group(function (): void {
            Route::get('/notifications', [NotificationLogController::class, 'index'])->name('notifications.index');
        });

        Route::middleware('permission:reports.view')->group(function (): void {
            Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/{report}', [AdminReportController::class, 'show'])->name('reports.show');
        });

        Route::middleware(['permission:reports.manage', 'throttle:admin-mutations'])->group(function (): void {
            Route::patch('/reports/{report}', [AdminReportController::class, 'update'])->name('reports.update');
        });

        Route::middleware('permission:settings.manage')->group(function (): void {
            Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
            Route::patch('/settings', [SettingController::class, 'update'])->middleware('throttle:admin-mutations')->name('settings.update');
        });
    });
