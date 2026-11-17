<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un séjour dans un logement meublé loué à la nuit. Il se compte par tranches de 24 heures :
 * arrivé le 12 à 14 h pour 2 jours, le client libère le logement le 14 à 14 h.
 */
class Stay extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'unit_id', 'guest_id', 'user_id', 'check_in_at', 'days', 'check_out_at',
        'daily_rate', 'amount', 'paid_amount', 'method', 'reference', 'notes',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'days' => 'integer',
        'whatsapp_opened_at' => 'datetime',
        'sms_opened_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function checkOutFor(Carbon $checkIn, int $days): Carbon
    {
        return $checkIn->copy()->addHours(24 * $days);
    }

    // « S-2026-0007 » : le numéro de la facture du séjour.
    public function number(): string
    {
        return 'S-'.$this->check_in_at->format('Y').'-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function balance(): float
    {
        return max(0, round((float) $this->amount - (float) $this->paid_amount, 3));
    }

    public function isPaid(): bool
    {
        return $this->balance() <= 0;
    }

    public function inProgress(): bool
    {
        return $this->check_in_at->lte(now()) && $this->check_out_at->gt(now());
    }

    public function daysLabel(): string
    {
        return $this->days > 1 ? __(':v1 jours', ['v1' => $this->days]) : __(':v1 jour', ['v1' => $this->days]);
    }

    // Les séjours qui chevauchent l'intervalle donné (bornes exclues : un départ à 14 h libère pour une arrivée à 14 h).
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('check_in_at', '<', $to)->where('check_out_at', '>', $from);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('check_in_at', '<=', now())->where('check_out_at', '>', now());
    }
}
