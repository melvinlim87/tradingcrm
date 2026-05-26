<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Mt5AccountController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // MT5 Account management
    Route::get('/accounts', [Mt5AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [Mt5AccountController::class, 'store'])->name('accounts.store');
    Route::put('/accounts/{account}', [Mt5AccountController::class, 'update'])->name('accounts.update');
    Route::delete('/accounts/{account}', [Mt5AccountController::class, 'destroy'])->name('accounts.destroy');

    // AI Analysis
    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
    Route::post('/analysis/generate', [AnalysisController::class, 'generate'])->name('analysis.generate');
    Route::get('/analysis/{id}', [AnalysisController::class, 'show'])->name('analysis.show');
    Route::get('/analysis/{id}/status', [AnalysisController::class, 'status'])->name('analysis.status');
    Route::post('/analysis/refresh-mt5-news', [AnalysisController::class, 'refreshMt5News'])->name('analysis.refresh-mt5-news');
    Route::get('/analysis/news-refresh/{id}/status', [AnalysisController::class, 'refreshMt5NewsStatus'])->name('analysis.refresh-mt5-news.status');

    // User management (administrator + admin only — enforced in controller)
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    // Administrator-only — dedicated Admins management page
    Route::get('/admins', [UserController::class, 'admins'])->name('admins.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
