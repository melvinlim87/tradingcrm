<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Mt5Account extends Model
{
    use HasFactory;

    protected $table = 'mt5_accounts';

    protected $fillable = [
        'account_number',
        'nickname',
        'broker',
        'server',
        'currency',
        'leverage',
        'balance',
        'equity',
        'margin',
        'free_margin',
        'margin_level',
        'floating_pnl',
        'drawdown_percent',
        'drawdown_alert_threshold',
        'status',
        'last_ping_at',
        'created_by',
    ];

    protected $casts = [
        'account_number' => 'integer',
        'leverage' => 'integer',
        'balance' => 'decimal:2',
        'equity' => 'decimal:2',
        'margin' => 'decimal:2',
        'free_margin' => 'decimal:2',
        'margin_level' => 'decimal:2',
        'floating_pnl' => 'decimal:2',
        'drawdown_percent' => 'decimal:4',
        'drawdown_alert_threshold' => 'decimal:2',
        'last_ping_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function telegramTopic(): HasOne
    {
        return $this->hasOne(TelegramTopic::class);
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nickname
                ? "{$this->nickname} (#{$this->account_number})"
                : "#{$this->account_number}",
        );
    }

    public function isOnline(): bool
    {
        return $this->status === 'online'
            && $this->last_ping_at
            && $this->last_ping_at->gt(now()->subMinute());
    }
}
