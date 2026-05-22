<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyAnalysis extends Model
{
    use HasFactory;

    protected $table = 'currency_analyses';

    protected $fillable = [
        'symbol',
        'week_start',
        'week_end',
        'charts',
        'news_snapshot',
        'outlook',
        'bias_score',
        'confidence',
        'summary',
        'market_structure',
        'support_resistance',
        'news_impact',
        'trade_ideas',
        'prompt_used',
        'raw_response',
        'openrouter_model',
        'tokens_used',
        'cost_usd',
        'status',
        'error_message',
        'user_id',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'charts' => 'array',
        'news_snapshot' => 'array',
        'market_structure' => 'array',
        'support_resistance' => 'array',
        'news_impact' => 'array',
        'trade_ideas' => 'array',
        'raw_response' => 'array',
        'bias_score' => 'integer',
        'confidence' => 'float',
        'tokens_used' => 'integer',
        'cost_usd' => 'decimal:6',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
