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
    ];

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
