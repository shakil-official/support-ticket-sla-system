<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReplyController;
use App\Http\Controllers\TicketAssignmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketStatusController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // ---- Tickets ------------------------------------------------------------------
    // {ticket} is bound by ticket_number (Ticket::getRouteKeyName), e.g. /tickets/TKT-2026-000001.
    // Authorization is checked inside the controllers / form requests via TicketPolicy.
    Route::resource('tickets', TicketController::class);

    Route::post('tickets/{ticket}/claim', [TicketAssignmentController::class, 'claim'])->name('tickets.claim');
    Route::post('tickets/{ticket}/assign', [TicketAssignmentController::class, 'assign'])->name('tickets.assign');
    Route::patch('tickets/{ticket}/status', TicketStatusController::class)->name('tickets.status');
    Route::post('tickets/{ticket}/replies', [ReplyController::class, 'store'])
        ->middleware('throttle:ticket-write')
        ->name('tickets.replies.store');

    Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');

    // ---- Notifications (bell) -----------------------------------------------------
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');

    // ---- Profile (Laravel Breeze) -------------------------------------------------
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ---- CSV import (Gate: import-tickets) ----------------------------------------
    Route::middleware('can:import-tickets')->prefix('imports')->name('imports.')->group(function () {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::post('/', [ImportController::class, 'store'])->name('store');
        Route::get('template', [ImportController::class, 'template'])->name('template');
        Route::get('{batch}', [ImportController::class, 'show'])->name('show');
        Route::get('{batch}/progress', [ImportController::class, 'progress'])->name('progress');
        Route::get('{batch}/errors', [ImportController::class, 'errors'])->name('errors');
    });

    // ---- Administration (Gates: manage-settings / manage-users) -------------------
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('can:manage-settings')->group(function () {
            Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings/sla', [SettingsController::class, 'updateSla'])->name('settings.sla');
            Route::put('settings/hours', [SettingsController::class, 'updateHours'])->name('settings.hours');
            Route::post('settings/holidays', [SettingsController::class, 'storeHoliday'])->name('settings.holidays.store');
            Route::delete('settings/holidays/{holiday}', [SettingsController::class, 'destroyHoliday'])->name('settings.holidays.destroy');
        });

        Route::middleware('can:manage-users')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        });
    });
});

require __DIR__.'/auth.php';
