<?php

use App\Http\Controllers\Settings\AboutController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    // Read-only: identity is managed through User Management (Phase 13) —
    // names are immutable after creation and users are archived, never
    // self-deleted, so there is no update or destroy route.
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Route::inertia (not a closure) keeps the route table route:cache-able.
    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance');

    Route::get('settings/about', [AboutController::class, 'show'])->name('about');
});
