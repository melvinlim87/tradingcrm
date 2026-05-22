<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramTopic extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'thread_id',
        'mt5_account_id',
        'description',
        'enabled',
    ];

    protected $casts = [
        'thread_id' => 'integer',
        'enabled' => 'boolean',
    ];

    public function mt5Account(): BelongsTo
    {
        return $this->belongsTo(Mt5Account::class);
    }

    public static function threadIdFor(string $name): ?int
    {
        return static::query()
            ->where('name', $name)
            ->where('enabled', true)
            ->value('thread_id');
    }

    public static function threadIdForAccount(int $mt5AccountId): ?int
    {
        return static::query()
            ->where('mt5_account_id', $mt5AccountId)
            ->where('enabled', true)
            ->value('thread_id');
    }
}
