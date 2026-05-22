<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderHistory extends Model
{
    use HasFactory;

    protected $table = 'orders_history';

    protected $fillable = [
        'mt5_account_id', 'ticket', 'position_id', 'symbol', 'type', 'volume',
        'open_price', 'close_price', 'sl', 'tp',
        'profit', 'swap', 'commission', 'pnl',
        'magic', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'ticket' => 'integer',
        'position_id' => 'integer',
        'magic' => 'integer',
        'volume' => 'decimal:2',
        'open_price' => 'decimal:6',
        'close_price' => 'decimal:6',
        'sl' => 'decimal:6',
        'tp' => 'decimal:6',
        'profit' => 'decimal:2',
        'swap' => 'decimal:2',
        'commission' => 'decimal:2',
        'pnl' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Mt5Account::class, 'mt5_account_id');
    }
}
