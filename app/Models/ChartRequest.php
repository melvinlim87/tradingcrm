<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartRequest extends Model
{
    use HasFactory;

    public const REQUIRED_TIMEFRAMES = ['H4', 'D1', 'W1'];

    protected $fillable = [
        'currency_analysis_id',
        'symbol',
        'status',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(CurrencyAnalysis::class, 'currency_analysis_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(ChartExport::class);
    }

    public function hasAllTimeframes(): bool
    {
        $received = $this->exports()->pluck('timeframe')->all();

        foreach (self::REQUIRED_TIMEFRAMES as $tf) {
            if (! in_array($tf, $received, true)) {
                return false;
            }
        }

        return true;
    }

    public function imageUrlMap(): array
    {
        return $this->exports()
            ->orderByRaw("FIELD(timeframe, 'H4','D1','W1')")
            ->get()
            ->mapWithKeys(fn (ChartExport $e) => [$e->timeframe => $e->public_url])
            ->all();
    }
}
