<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ligne technique : son organisation est celle de son paiement et de sa facture.
class PaymentAllocation extends Model
{
    protected $fillable = ['payment_id', 'invoice_id', 'amount'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
