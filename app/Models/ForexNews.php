<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ForexNews extends Model
{
    use HasFactory;

    protected $table = 'forex_news';

    protected $fillable = [
        'title',
        'subject',
        'category',
        'body_html',
        'currency',
        'impact',
        'forecast',
        'previous',
        'actual',
        'measures',
        'usual_effect',
        'traders_care',
        'notes',
        'event_at',
        'raw_date',
        'source',
        'mt5_event_id',
        'external_id',
        'source_url',
        'unit',
        'sector',
        'frequency',
        'event_type',
    ];

    /**
     * Quick check whether this row is broker-analyst news (HTML body) vs
     * a structured calendar event.
     */
    public function isBrokerNews(): bool
    {
        return $this->source === 'mt5_broker_news' || ! empty($this->body_html);
    }

    protected $casts = [
        'event_at' => 'datetime',
    ];

    public function scopeBetween(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('event_at', [$start, $end]);
    }

    public function scopeForCurrencies(Builder $query, array $currencies): Builder
    {
        return $query->whereIn('currency', array_map('strtoupper', $currencies));
    }

    public function scopeHighImpact(Builder $query): Builder
    {
        return $query->where('impact', 'HIGH');
    }
}
