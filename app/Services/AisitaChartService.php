<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fetches chart screenshots from the Aisita backend.
 *
 *   POST https://dev-backend.aisita.ai/api/gold-chart
 *   Authorization: Bearer <token>
 *   { "symbol": "EURUSD" }
 *
 * Response:
 *   {
 *     "success": true,
 *     "data": [
 *       { "type":"image", "content_type":"image/png", "data":"<base64>" },   // M15
 *       { "type":"image", "content_type":"image/png", "data":"<base64>" },   // H1
 *       { "type":"image", "content_type":"image/png", "data":"<base64>" },   // H4
 *     ]
 *   }
 *
 * Replaces the EA-driven ChartExporter path.  The returned dataset can be
 * persisted to disk (for showing in the UI) and / or used directly as
 * data: URIs for the OpenRouter vision request.
 */
class AisitaChartService
{
    private const TIMEFRAMES = ['M15', 'H1', 'H4'];

    public function isConfigured(): bool
    {
        $url   = config('services.aisita.chart_url');
        $token = config('services.aisita.token');
        return ! empty($url) && ! empty($token);
    }

    /**
     * Fetch + decode 3 charts for a symbol.
     *
     * @return array{
     *   timeframes: array<string,string>,   // "M15" => "<base64>", ...
     *   data_uris:  array<string,string>,   // "M15" => "data:image/png;base64,<base64>", ...
     * }
     *
     * @throws RuntimeException on transport / parse / count errors
     */
    public function fetch(string $symbol): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Aisita chart API is not configured (set AISITA_CHART_URL + AISITA_CHART_TOKEN)');
        }

        $url   = (string) config('services.aisita.chart_url');
        $token = (string) config('services.aisita.token');

        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept'        => 'application/json',
            ])
            ->asJson()
            ->post($url, ['symbol' => strtoupper($symbol)]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Aisita chart API failed (HTTP {$response->status()}): "
                . mb_substr($response->body(), 0, 400),
            );
        }

        $payload = $response->json();
        if (! ($payload['success'] ?? false)) {
            throw new RuntimeException(
                'Aisita chart API returned success=false: '
                . mb_substr($response->body(), 0, 400),
            );
        }

        $images = $payload['data'] ?? [];
        if (! is_array($images) || count($images) === 0) {
            throw new RuntimeException('Aisita chart API returned no images');
        }

        $timeframes = [];
        $dataUris   = [];

        foreach ($images as $i => $img) {
            $base64 = (string) ($img['data'] ?? '');
            $mime   = (string) ($img['content_type'] ?? 'image/png');
            if ($base64 === '') {
                continue;
            }
            // Map by position — Aisita returns them in M15 / H1 / H4 order.
            $tf = self::TIMEFRAMES[$i] ?? ('TF' . ($i + 1));
            $timeframes[$tf] = $base64;
            $dataUris[$tf]   = 'data:' . $mime . ';base64,' . $base64;
        }

        if (empty($timeframes)) {
            throw new RuntimeException('Aisita chart API: all image payloads were empty');
        }

        Log::info('AisitaChartService: fetched charts', [
            'symbol' => $symbol,
            'count'  => count($timeframes),
            'tfs'    => array_keys($timeframes),
        ]);

        return [
            'timeframes' => $timeframes,
            'data_uris'  => $dataUris,
        ];
    }

    /**
     * Persist the fetched charts under storage/app/public/charts/aisita/{analysisId}/
     * so the UI can render them via a public URL.
     *
     * @param  array<string,string>  $timeframes  Result of fetch()['timeframes']
     * @return array<string,string>  "M15" => "https://.../storage/charts/aisita/123/M15.png", ...
     */
    public function persistForAnalysis(int $analysisId, array $timeframes): array
    {
        $urls = [];
        foreach ($timeframes as $tf => $base64) {
            $binary = base64_decode($base64, true);
            if ($binary === false) {
                continue;
            }
            $path = sprintf('charts/aisita/%d/%s.png', $analysisId, $tf);
            Storage::disk('public')->put($path, $binary);
            $urls[$tf] = Storage::disk('public')->url($path);
        }
        return $urls;
    }
}
