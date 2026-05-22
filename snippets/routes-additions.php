<?php

// Drop the relevant blocks into routes/web.php and routes/api.php.

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\Api\EaChartController;
use Illuminate\Support\Facades\Route;

// === routes/web.php (inside Route::middleware('auth')->group) ===

Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
Route::post('/analysis/generate', [AnalysisController::class, 'generate'])->name('analysis.generate');
Route::get('/analysis/{id}', [AnalysisController::class, 'show'])->name('analysis.show');
Route::get('/analysis/{id}/status', [AnalysisController::class, 'status'])->name('analysis.status');


// === routes/api.php (all EA endpoints sit behind the EA token middleware) ===

Route::middleware(\App\Http\Middleware\VerifyEaToken::class)
    ->prefix('ea')
    ->group(function () {
        // Risk Monitor EA pushes account / positions / pending / history every 10s
        // Route::post('/push', [\App\Http\Controllers\Api\EaPushController::class, 'store']);

        // Chart Exporter EA polls + uploads
        Route::get('/chart-requests/pending', [EaChartController::class, 'pending']);
        Route::post('/chart-requests/{id}/fail', [EaChartController::class, 'fail']);
        Route::post('/chart-exports', [EaChartController::class, 'upload']);
    });


// === bootstrap/app.php (Laravel 11 — register middleware alias) ===
//
// ->withMiddleware(function (Middleware $middleware) {
//     $middleware->alias([
//         'verify.ea' => \App\Http\Middleware\VerifyEaToken::class,
//     ]);
// })


// === app/Console/Kernel.php — schedule() method ===
//
// $schedule->command('news:scrape')
//          ->dailyAt('06:00')
//          ->timezone('Asia/Singapore')
//          ->onOneServer()
//          ->withoutOverlapping();
