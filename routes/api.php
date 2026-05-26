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

    // EA pushes MT5-native calendar events (MetaQuotes has no public REST,
    // so the EA is the only conduit). Backend upserts into forex_news with source='mt5'.
    Route::post('/news', [EaNewsController::class, 'push']);

    // EA pushes MT5 BROKER news (Trading Central style, HTML bodies)
    // — those that appear in MT5's News tab. Source = 'mt5_broker_news'.
    Route::post('/news/broker', [EaNewsController::class, 'pushBrokerNews']);

    // On-demand MT5 news fetch — admin triggers from UI, EA polls + fulfills.
    Route::get('/news-requests/pending', [EaNewsController::class, 'pending']);
    Route::post('/news-requests/{id}/complete', [EaNewsController::class, 'complete']);
    Route::post('/news-requests/{id}/fail',     [EaNewsController::class, 'fail']);
});
