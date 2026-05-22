<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'chart_request_id',
        'symbol',
        'timeframe',
        'storage_path',
        'public_url',
        'file_size',
        'mime',
        'bid',
        'ask',
        'digits',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'file_size' => 'integer',
        'bid' => 'decimal:6',
        'ask' => 'decimal:6',
        'digits' => 'integer',
    ];

    public function chartRequest(): BelongsTo
    {
        return $this->belongsTo(ChartRequest::class);
    }
}
