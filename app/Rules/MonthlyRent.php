<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Loyer par mois ou tarif journalier, selon la case « Meublé » : le formulaire n'affiche que l'un des deux.
 *  - non meublé : le loyer par mois est obligatoire ;
 *  - meublé : le tarif journalier est obligatoire, sauf si un loyer par mois existe déjà (meublé loué au mois).
 * Vérifié avec les autres champs, pour que toutes les erreurs s'affichent ensemble, sur le champ visible.
 * « rent » ou « units.3.rent » : les cases voisines « furnished » et « daily_rate » sont lues au même niveau.
 */
class MonthlyRent implements DataAwareRule, ValidationRule
{
    // Vérifiée même quand le champ est vide.
    public bool $implicit = true;

    private array $data = [];

    // $field : « rent » (loyer par mois) ou « daily_rate » (tarif journalier).
    public function __construct(private string $field = 'rent')
    {
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $prefix = substr($attribute, 0, -strlen($this->field));
        $furnished = filter_var(data_get($this->data, $prefix.'furnished', false), FILTER_VALIDATE_BOOLEAN);
        $positive = fn ($amount) => is_numeric($amount) && (float) $amount > 0;
        $rent = data_get($this->data, $prefix.'rent');
        $daily = data_get($this->data, $prefix.'daily_rate');

        if ($this->field === 'rent' && ! $furnished && ! $positive($rent)) {
            $fail(__('Le loyer par mois est obligatoire pour un logement non meublé.'));
        }

        if ($this->field === 'daily_rate' && $furnished && ! $positive($daily) && ! $positive($rent)) {
            $fail(__('Le tarif journalier est obligatoire pour un logement meublé.'));
        }
    }
}
