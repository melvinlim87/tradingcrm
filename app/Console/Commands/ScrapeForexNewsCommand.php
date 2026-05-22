<?php

namespace App\Console\Commands;

use App\Models\ForexNews;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScrapeForexNewsCommand extends Command
{
    protected $signature = 'news:scrape {--weeks=last,this,next : Which feeds to pull}';

    protected $description = 'Scrape ForexFactory calendar (last, this, next week) into forex_news';

    private const FEEDS = [
        'last' => 'https://nfs.faireconomy.media/ff_calendar_lastweek.json',
        'this' => 'https://nfs.faireconomy.media/ff_calendar_thisweek.json',
        'next' => 'https://nfs.faireconomy.media/ff_calendar_nextweek.json',
    ];

    public function handle(): int
    {
        $weeks = array_filter(array_map('trim', explode(',', $this->option('weeks'))));
        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($weeks as $week) {
            $url = self::FEEDS[$week] ?? null;
            if (! $url) {
                $this->warn("Unknown week key: {$week}");
                continue;
            }

            $this->info("Fetching {$week} → {$url}");

            try {
                $response = Http::timeout(120)->get($url);

                if ($response->failed()) {
                    $failed++;
                    Log::error('ForexFactory fetch failed', [
                        'url' => $url,
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                foreach ($response->json() ?? [] as $item) {
                    $eventAt = $this->parseDate($item['date'] ?? null);
                    if (! $eventAt) {
                        continue;
                    }

                    $record = ForexNews::firstOrCreate(
                        [
                            'title' => $item['title'] ?? '',
                            'event_at' => $eventAt,
                        ],
                        [
                            'currency' => strtoupper(trim($item['country'] ?? '')),
                            'impact' => $this->normalizeImpact($item['impact'] ?? 'Low'),
                            'forecast' => $this->nullIfEmpty($item['forecast'] ?? null),
                            'previous' => $this->nullIfEmpty($item['previous'] ?? null),
                            'raw_date' => $item['date'] ?? null,
                        ]
                    );

                    if ($record->wasRecentlyCreated) {
                        $imported++;
                    } else {
                        $skipped++;
                        $this->updateIfChanged($record, $item);
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('ForexFactory scrape error', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Imported: {$imported}, Existing: {$skipped}, Failed feeds: {$failed}");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeImpact(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        return match ($value) {
            'HIGH' => 'HIGH',
            'MED', 'MEDIUM' => 'MEDIUM',
            'HOLIDAY' => 'HOLIDAY',
            default => 'LOW',
        };
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function updateIfChanged(ForexNews $record, array $item): void
    {
        $newActual = $this->nullIfEmpty($item['actual'] ?? null);
        $newForecast = $this->nullIfEmpty($item['forecast'] ?? null);

        $dirty = [];

        if ($newActual !== null && $newActual !== $record->actual) {
            $dirty['actual'] = $newActual;
        }
        if ($newForecast !== null && $newForecast !== $record->forecast) {
            $dirty['forecast'] = $newForecast;
        }

        if ($dirty) {
            $record->update($dirty);
        }
    }
}
