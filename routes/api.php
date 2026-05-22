<?php

use App\Http\Controllers\Api\EaChartController;
use App\Http\Controllers\Api\EaNewsController;
use App\Http\Controllers\Api\EaPushController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — all consumed by MT5 EAs (auth via Bearer / X-EA-Token).
|--------------------------------------------------------------------------
*/

Route::middleware('verify.ea')->prefix('ea')->group(function () {
    // Risk Monitor EA — push account / positions / pending / history (10s)
    Route::post('/push', [EaPushController::class, 'store']);

    // Chart Exporter EA — poll for pending requests + upload PNGs
    Route::get('/chart-requests/pending', [EaChartController::class, 'pending']);
    Route::post('/chart-requests/{id}/fail', [EaChartController::class, 'fail']);
    Route::post('/chart-exports', [EaChartController::class, 'upload']);

    // News for EA dashboard panel
    Route::get('/news/latest', [EaNewsController::class, 'latest']);
});
