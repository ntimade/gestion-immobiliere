<?php

namespace App\Models\Concerns;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Limite toutes les requêtes à l'organisation de l'utilisateur connecté et
 * renseigne organisation_id à la création : un propriétaire ne peut ni voir ni
 * modifier les données d'un autre, y compris via un identifiant dans l'URL.
 */
trait BelongsToOrganisation
{
    protected static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope('organisation', function (Builder $query) {
            if (Auth::check()) {
                $query->where($query->getModel()->getTable().'.organisation_id', Auth::user()->organisation_id);
            }
        });

        static::creating(function ($model) {
            if (Auth::check() && ! $model->organisation_id) {
                $model->organisation_id = Auth::user()->organisation_id;
            }
        });
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
