<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'mt5_account_id',
        'balance',
        'equity',
        'margin',
        'free_margin',
        'margin_level',
        'floating_pnl',
        'drawdown_percent',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'balance' => 'decimal:2',
        'equity' => 'decimal:2',
        'margin' => 'decimal:2',
        'free_margin' => 'decimal:2',
        'margin_level' => 'decimal:2',
        'floating_pnl' => 'decimal:2',
        'drawdown_percent' => 'decimal:4',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Mt5Account::class, 'mt5_account_id');
    }
}
