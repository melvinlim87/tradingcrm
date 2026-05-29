<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
// (OrderHistory used by lazy fallback in roi_pct accessor)
use App\Models\OrderHistory;

class Mt5Account extends Model
{
    use HasFactory;

    protected $table = 'mt5_accounts';

    protected $fillable = [
        'account_number',
        'account_name',
        'broker',
        'server',
        'currency',
        'leverage',
        'balance',
        'equity',
        'initial_balance',
        'total_deposits',
        'total_withdrawals',
        'net_deposits',
        'peak_equity',
        'margin',
        'free_margin',
        'margin_level',
        'floating_pnl',
        'drawdown_percent',
        'max_abs_drawdown_pct',
        'max_eq_drawdown_pct',
        'drawdown_alert_threshold',
        'notes',
        'status',
        'last_ping_at',
        'created_by',
    ];

    /**
     * Auto-include computed roi_pct in every serialization (Dashboard, JSON, etc.)
     */
    protected $appends = ['roi_pct', 'capital_base'];

    protected $casts = [
        'account_number' => 'integer',
        'leverage' => 'integer',
        'balance' => 'decimal:2',
        'equity' => 'decimal:2',
        'initial_balance' => 'decimal:2',
        'total_deposits' => 'decimal:2',
        'total_withdrawals' => 'decimal:2',
        'net_deposits' => 'decimal:2',
        'peak_equity' => 'decimal:2',
        'margin' => 'decimal:2',
        'free_margin' => 'decimal:2',
        'margin_level' => 'decimal:2',
        'floating_pnl' => 'decimal:2',
        'drawdown_percent' => 'decimal:4',
        'max_abs_drawdown_pct' => 'decimal:4',
        'max_eq_drawdown_pct' => 'decimal:4',
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
            get: fn () => $this->account_name
                ? "{$this->account_name} (#{$this->account_number})"
                : "#{$this->account_number}",
        );
    }

    /**
     * Capital base used as the ROI denominator — prefer total_deposits
     * (gross money the user put in, from EA's DEAL_TYPE_BALANCE scan),
     * else fall back to initial_balance (first EA-ping balance). Returns
     * null if we have neither, to avoid divide-by-zero.
     *
     * We deliberately use TOTAL deposits (not net_deposits) here, because:
     *   - "What return have my trades made on the money I put in?" is the
     *     intuitive question.
     *   - Withdrawals are typically REALISED profit being taken out, so
     *     subtracting them inflates ROI artificially.
     */
    protected function capitalBase(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->total_deposits !== null && (float) $this->total_deposits > 0) {
                    return (float) $this->total_deposits;
                }
                if ($this->initial_balance !== null && (float) $this->initial_balance > 0) {
                    return (float) $this->initial_balance;
                }
                return null;
            },
        );
    }

    /**
     * ROI % = closed_profit_total / capital_base * 100
     *
     * `closed_profit_total` is set by DashboardController (sum of all
     * orders_history.pnl for this account). If not pre-set, falls back
     * to a direct query so accessor still works in tinker / API.
     *
     * Returns null when:
     *   - capital_base is unknown
     *   - we have no closed trades AND withdrawals haven't been made
     *     (a brand-new account → don't show misleading 0%)
     */
    protected function roiPct(): Attribute
    {
        return Attribute::make(
            get: function () {
                $base = $this->capital_base;
                if ($base === null || $base <= 0) return null;

                // Use controller-injected value if present (fast batch query);
                // otherwise hit DB lazily (slow but correct for ad-hoc use).
                $closedProfit = $this->attributes['closed_profit_total']
                    ?? OrderHistory::where('mt5_account_id', $this->id)->sum('pnl');

                return round(((float) $closedProfit / $base) * 100, 2);
            },
        );
    }

    public function isOnline(): bool
    {
        return $this->status === 'online'
            && $this->last_ping_at
            && $this->last_ping_at->gt(now()->subMinute());
    }
}
