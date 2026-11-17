<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use BelongsToOrganisation, SoftDeletes;

    // Une part de la caution retenue, affectée aux loyers impayés à la sortie.
    public const FROM_DEPOSIT = 'deposit';

    protected $fillable = ['organisation_id', 'lease_id', 'user_id', 'amount', 'paid_at', 'method', 'reference', 'source'];

    protected $casts = ['paid_at' => 'date', 'whatsapp_opened_at' => 'datetime', 'sms_opened_at' => 'datetime'];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    // « novembre 2026, décembre 2026 » : les mois réglés par ce paiement.
    public function coveredLabel(): string
    {
        return $this->allocations->sortBy(fn (PaymentAllocation $allocation) => $allocation->invoice->period)
            ->map(fn (PaymentAllocation $allocation) => $allocation->invoice->label())->implode(', ') ?: '—';
    }
}
