<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeCurrencyJob;
use App\Models\ChartExport;
use App\Models\ChartRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EaChartController extends Controller
{
    /**
     * EA polls this every InpPollInterval seconds.
     *   GET /api/ea/chart-requests/pending
     *   → { data: [ { id, symbol }, ... ] }
     */
    public function pending(): JsonResponse
    {
        $pending = ChartRequest::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->orderBy('id')
            ->limit(10)
            ->get(['id', 'symbol']);

        ChartRequest::whereIn('id', $pending->pluck('id'))
            ->where('status', 'pending')
            ->update(['status' => 'in_progress']);

        return response()->json(['data' => $pending]);
    }

    /**
     * EA POSTs each captured PNG here as raw binary body.
     *   POST /api/ea/chart-exports
     *   Headers: X-Symbol, X-Timeframe, X-Request-Id, Content-Type: image/png
     */
    public function upload(Request $request): JsonResponse
    {
        $requestId = (int) $request->header('X-Request-Id');
        $symbol = strtoupper((string) $request->header('X-Symbol'));
        $timeframe = strtoupper((string) $request->header('X-Timeframe'));

        if ($requestId <= 0 || $symbol === '' || $timeframe === '') {
            return response()->json([
                'error' => 'Missing X-Request-Id / X-Symbol / X-Timeframe headers',
            ], 422);
        }

        if (! in_array($timeframe, ChartRequest::REQUIRED_TIMEFRAMES, true)) {
            return response()->json(['error' => "Invalid timeframe: {$timeframe}"], 422);
        }

        $chartRequest = ChartRequest::find($requestId);
        if (! $chartRequest) {
            return response()->json(['error' => 'Request not found'], 404);
        }

        $binary = $request->getContent();
        if (strlen($binary) === 0) {
            return response()->json(['error' => 'Empty body'], 422);
        }

        $filename = sprintf(
            'charts/%d/%s_%s_%s.png',
            $chartRequest->id,
            $symbol,
            $timeframe,
            Str::random(8),
        );

        Storage::disk('public')->put($filename, $binary);

        $bid = (float) $request->header('X-Bid', 0);
        $ask = (float) $request->header('X-Ask', 0);
        $digits = (int) $request->header('X-Digits', 5);

        $export = ChartExport::updateOrCreate(
            ['chart_request_id' => $chartRequest->id, 'timeframe' => $timeframe],
            [
                'symbol' => $symbol,
                'storage_path' => $filename,
                'public_url' => Storage::disk('public')->url($filename),
                'file_size' => strlen($binary),
                'mime' => 'image/png',
                'bid' => $bid > 0 ? $bid : null,
                'ask' => $ask > 0 ? $ask : null,
                'digits' => $digits ?: null,
                'captured_at' => now(),
            ],
        );

        if ($chartRequest->fresh()->hasAllTimeframes()) {
            $chartRequest->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('ChartRequest completed', [
                'request_id' => $chartRequest->id,
                'analysis_id' => $chartRequest->currency_analysis_id,
            ]);

            if ($chartRequest->currency_analysis_id) {
                // Run the OpenRouter analysis SYNCHRONOUSLY inside this request
                // (no queue worker needed). The EA's WebRequest upload waits for
                // the response — its timeout is set to 120s in TradingCRM_EA.mq5.
                try {
                    AnalyzeCurrencyJob::dispatchSync($chartRequest->currency_analysis_id);
                } catch (\Throwable $e) {
                    Log::error('Sync analysis failed', [
                        'analysis_id' => $chartRequest->currency_analysis_id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail the chart upload itself — the job's failed() handler
                    // has already marked the analysis row as 'failed'.
                }
            }
        }

        return response()->json([
            'export_id' => $export->id,
            'request_status' => $chartRequest->fresh()->status,
        ]);
    }

    /**
     * EA reports a request couldn't be fulfilled (symbol not in Market Watch, etc.)
     *   POST /api/ea/chart-requests/{id}/fail
     */
    public function fail(Request $request, int $id): JsonResponse
    {
        $chartRequest = ChartRequest::findOrFail($id);
        $reason = (string) ($request->input('reason') ?? 'unspecified');

        $chartRequest->update([
            'status' => 'failed',
            'error_message' => $reason,
        ]);

        if ($chartRequest->currency_analysis_id) {
            $chartRequest->analysis()->update([
                'status' => 'failed',
                'error_message' => "Chart export failed: {$reason}",
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
