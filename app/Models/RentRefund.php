<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Des loyers payés pour des mois après la sortie, rendus au locataire.
class RentRefund extends Model
{
    use BelongsToOrganisation;

    protected $fillable = ['organisation_id', 'lease_id', 'user_id', 'amount', 'refunded_on', 'note', 'cancelled_at'];

    protected $casts = ['refunded_on' => 'date', 'cancelled_at' => 'datetime'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }
}
