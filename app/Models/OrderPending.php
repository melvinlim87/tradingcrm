<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPending extends Model
{
    use HasFactory;

    protected $table = 'orders_pending';

    protected $fillable = [
        'mt5_account_id', 'ticket', 'symbol', 'type', 'volume',
        'entry', 'sl', 'tp', 'magic', 'created_ea_at', 'expires_at',
    ];

    protected $casts = [
        'created_ea_at' => 'datetime',
        'expires_at' => 'datetime',
        'ticket' => 'integer',
        'magic' => 'integer',
        'volume' => 'decimal:2',
        'entry' => 'decimal:6',
        'sl' => 'decimal:6',
        'tp' => 'decimal:6',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Mt5Account::class, 'mt5_account_id');
    }
}
