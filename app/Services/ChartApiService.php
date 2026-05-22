<?php

namespace App\Services;

/**
 * @deprecated Charts are now produced by the ChartExporter EA pushing screenshots
 *             into the chart_exports table. Kept as a stub in case you want to
 *             re-add an external chart provider later.
 *
 * Live flow: AnalysisController::generate() creates a ChartRequest;
 *            the EA polls /api/ea/chart-requests/pending and uploads PNGs to
 *            /api/ea/chart-exports; AnalyzeCurrencyJob reads the public URLs
 *            from chart_exports and feeds them straight to OpenRouter.
 */
class ChartApiService
{
    public function fetchCharts(string $symbol, array $timeframes = ['H4', 'D1', 'W1']): array
    {
        throw new \LogicException(
            'ChartApiService is deprecated. Charts are now exported by the ' .
            'ChartExporter EA. See app/Jobs/AnalyzeCurrencyJob.php.'
        );
    }
}
