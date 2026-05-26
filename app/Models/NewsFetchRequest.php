<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsFetchRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'requested_by',
        'events_imported',
        'events_updated',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'events_imported' => 'integer',
        'events_updated'  => 'integer',
        'completed_at'    => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
