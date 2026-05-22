<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterService
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $defaultModel = null,
    ) {
    }

    /**
     * Send a multimodal request to OpenRouter and parse the JSON response.
     *
     * @param  array<string,string>  $imageUrls  ["H4" => "url|data uri", ...]
     * @return array{parsed: array, raw: array, model: string, tokens: int}
     */
    public function analyze(string $prompt, array $imageUrls = [], ?string $model = null): array
    {
        $apiKey = $this->apiKey ?? config('services.openrouter.key');
        if (! $apiKey) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured');
        }

        $model ??= $this->defaultModel ?? config('services.openrouter.model', 'anthropic/claude-3.5-sonnet');

        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($imageUrls as $label => $url) {
            $content[] = ['type' => 'text', 'text' => "Chart [{$label}]:"];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        $response = Http::timeout(300)
            ->withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'HTTP-Referer' => config('app.url', ''),
                'X-Title' => 'TradingCRM',
            ])
            ->acceptJson()
            ->post(self::ENDPOINT, [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $content]],
                'temperature' => (float) config('services.openrouter.temperature', 0.3),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenRouter request failed (HTTP {$response->status()}): " . $response->body()
            );
        }

        $data = $response->json();
        $raw = $data['choices'][0]['message']['content'] ?? '';
        $parsed = $this->extractJson($raw);
        \Log::error('Repsonse', $data);

        if ($parsed === null) {
            throw new RuntimeException(
                'OpenRouter returned non-JSON content: ' . mb_substr($raw, 0, 500)
            );
        }

        return [
            'parsed' => $parsed,
            'raw' => $data,
            'model' => $data['model'] ?? $model,
            'tokens' => (int) ($data['usage']['total_tokens'] ?? 0),
        ];
    }

    private function extractJson(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.+\})\s*```/s', $raw, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/(\{.*\})/s', $raw, $m)) {
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
