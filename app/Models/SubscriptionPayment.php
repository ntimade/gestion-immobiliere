<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ORANGE_MONEY = 'orange_money';

    public const MOBILE_MONEY = 'mobile_money';

    public const CASH = 'cash';

    public const METHODS = [self::ORANGE_MONEY, self::MOBILE_MONEY, self::CASH];

    // Orange Money et MTN seront ajoutés ici quand leurs API seront branchées.
    public const AVAILABLE_METHODS = [self::CASH];

    protected $fillable = [
        'organisation_id', 'plan_id', 'requested_by', 'months', 'amount', 'currency', 'method', 'phone', 'reference',
        'status', 'period_start', 'period_end', 'handled_by', 'handled_at', 'note',
    ];

    protected $casts = [
        'amount' => 'float',
        'period_start' => 'date',
        'period_end' => 'date',
        'handled_at' => 'datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(SupportAdmin::class, 'handled_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function methodLabel(): string
    {
        return self::labelForMethod($this->method);
    }

    public static function labelForMethod(string $method): string
    {
        return match ($method) {
            self::ORANGE_MONEY => __('Orange Money'),
            self::MOBILE_MONEY => __('MTN Mobile Money'),
            default => __('Espèces'),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPROVED => __('Validé'),
            self::REJECTED => __('Refusé'),
            default => __('En attente'),
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::APPROVED => 'ok',
            self::REJECTED => 'ko',
            default => 'partial',
        };
    }

    public function amountLabel(): string
    {
        return SubscriptionPlan::formatAmount($this->amount, $this->currency);
    }
}
