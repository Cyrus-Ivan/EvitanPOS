<?php

use App\Http\Controllers\BusinessSettingsController;
use App\Http\Controllers\Inventory\CategoryController;
use App\Http\Controllers\Inventory\ItemController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Desktop app entry point: no public marketing page. Send everyone to the
// dashboard; the auth middleware bounces guests to the login screen.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    // Cashier-level: available to every authenticated user. Each destination is
    // a placeholder until its owning phase lands (see ROADMAP.md).
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    Route::inertia('pos', 'pos')->name('pos');                                          // Phase 8
    Route::inertia('sales', 'sales')->name('sales');                                    // Phase 9
    Route::inertia('login-history', 'login-history')->name('login-history');            // Phase 14

    // Inventory (Phase 5). Viewing is Cashier-level (read-only); every write is
    // gated to Admin+ below. Item Cost is further restricted to the Owner inside
    // the request/model layer, not here.
    Route::get('inventory', [ItemController::class, 'index'])->name('inventory');
    Route::get('inventory/categories', [CategoryController::class, 'index'])->name('categories.index');

    // Admin-level: Admins and the Owner.
    Route::middleware('role:admin')->group(function () {
        Route::inertia('reports', 'reports')->name('reports');                          // Phase 12
        Route::inertia('users', 'users')->name('users');                               // Phase 13
        Route::inertia('audit-logs', 'audit-logs')->name('audit-logs');                // Phase 15

        // Inventory writes (Phase 5). Literal segments are declared before the
        // {item} wildcard so create/categories are never captured as an id.
        Route::get('inventory/create', [ItemController::class, 'create'])->name('items.create');
        Route::post('inventory', [ItemController::class, 'store'])->name('items.store');
        Route::get('inventory/{item}/edit', [ItemController::class, 'edit'])->name('items.edit');
        Route::patch('inventory/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::patch('inventory/{item}/archive', [ItemController::class, 'archive'])->name('items.archive');
        Route::patch('inventory/{item}/restore', [ItemController::class, 'restore'])->name('items.restore');

        Route::post('inventory/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('inventory/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::patch('inventory/categories/{category}/archive', [CategoryController::class, 'archive'])->name('categories.archive');
        Route::patch('inventory/categories/{category}/restore', [CategoryController::class, 'restore'])->name('categories.restore');
    });

    // Owner-only.
    Route::middleware('role:owner')->group(function () {
        Route::get('business-settings', [BusinessSettingsController::class, 'edit'])->name('business-settings');      // Phase 4
        Route::patch('business-settings', [BusinessSettingsController::class, 'update'])->name('business-settings.update');
        Route::inertia('backup', 'backup')->name('backup');                                  // Phase 16
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
