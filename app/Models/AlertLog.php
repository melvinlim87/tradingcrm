<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'mt5_account_id', 'kind', 'value_at_trigger', 'threshold',
        'message', 'telegram_sent', 'telegram_error', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'telegram_sent' => 'boolean',
        'value_at_trigger' => 'decimal:4',
        'threshold' => 'decimal:4',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Mt5Account::class, 'mt5_account_id');
    }
}
