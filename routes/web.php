<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Desktop app entry point: no public marketing page. Send everyone to the
// dashboard; the auth middleware bounces guests to the login screen.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');

    // Phase 0 puts the full navigation in front of the user; each destination
    // is a placeholder until its owning phase lands (see ROADMAP.md). Real
    // route-level role authorization arrives in Phase 3.
    Route::inertia('pos', 'pos')->name('pos');                                          // Phase 8
    Route::inertia('sales', 'sales')->name('sales');                                    // Phase 9
    Route::inertia('inventory', 'inventory')->name('inventory');                        // Phase 5
    Route::inertia('reports', 'reports')->name('reports');                              // Phase 12
    Route::inertia('users', 'users')->name('users');                                    // Phase 13
    Route::inertia('login-history', 'login-history')->name('login-history');            // Phase 14
    Route::inertia('audit-logs', 'audit-logs')->name('audit-logs');                     // Phase 15
    Route::inertia('business-settings', 'business-settings')->name('business-settings'); // Phase 4
    Route::inertia('backup', 'backup')->name('backup');                                 // Phase 16
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
