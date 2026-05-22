<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderOpen extends Model
{
    use HasFactory;

    protected $table = 'orders_open';

    protected $fillable = [
        'mt5_account_id', 'ticket', 'symbol', 'type', 'volume',
        'open_price', 'current_price', 'sl', 'tp',
        'profit', 'swap', 'pnl', 'magic', 'opened_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'ticket' => 'integer',
        'magic' => 'integer',
        'volume' => 'decimal:2',
        'open_price' => 'decimal:6',
        'current_price' => 'decimal:6',
        'sl' => 'decimal:6',
        'tp' => 'decimal:6',
        'profit' => 'decimal:2',
        'swap' => 'decimal:2',
        'pnl' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Mt5Account::class, 'mt5_account_id');
    }
}
