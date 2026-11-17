<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use BelongsToOrganisation;

    public const MIXED = 'mixed';

    protected $fillable = ['name', 'kind', 'city', 'neighbourhood', 'address', 'description', 'latitude', 'longitude'];

    protected $attributes = ['kind' => self::MIXED];

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    // Le bien est lui-même un seul logement : un appartement, une chambre, un studio…
    public function isSingle(): bool
    {
        return $this->kind !== self::MIXED;
    }

    public static function kinds(): array
    {
        return array_merge(Unit::TYPES, [self::MIXED]);
    }

    public static function kindName(string $kind): string
    {
        return $kind === self::MIXED ? __('Ensemble de logements') : Unit::typeName($kind);
    }

    public function kindLabel(): string
    {
        return self::kindName($this->kind);
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    // « Bonapriso, Douala »
    public function place(): string
    {
        return collect([$this->neighbourhood, $this->city])->filter()->implode(', ');
    }

    public function mapUrl(): ?string
    {
        return $this->hasPosition() ? 'https://www.google.com/maps?q='.(float) $this->latitude.','.(float) $this->longitude : null;
    }

    /**
     * Ajoute deux montants par mois : current_income, les loyers des baux en cours, et
     * estimated_income, ce que le bien rapporterait tout loué (le loyer du bail s'il y en a un,
     * sinon le montant du logement).
     */
    public function scopeWithIncome(Builder $query): Builder
    {
        $leaseRent = '(SELECT leases.rent FROM leases WHERE leases.unit_id = units.id AND (COALESCE(leases.end_date, leases.notice_end_date) IS NULL OR COALESCE(leases.end_date, leases.notice_end_date) >= ?) ORDER BY leases.start_date DESC LIMIT 1)';
        $today = today()->toDateString();

        return $query->addSelect([
            'current_income' => Unit::selectRaw("COALESCE(SUM({$leaseRent}), 0)", [$today])->whereColumn('units.property_id', 'properties.id'),
            'estimated_income' => Unit::selectRaw("COALESCE(SUM(COALESCE({$leaseRent}, units.rent)), 0)", [$today])->whereColumn('units.property_id', 'properties.id'),
        ]);
    }
}
