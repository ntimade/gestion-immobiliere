<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Unit extends Model
{
    use BelongsToOrganisation;

    public const TYPES = ['apartment', 'room', 'studio', 'house', 'shop'];

    protected $fillable = [
        'property_id', 'number', 'type', 'rent', 'daily_rate', 'notes', 'floor', 'surface',
        'bedrooms', 'living_rooms', 'kitchens', 'bathrooms', 'furnished', 'amenities',
    ];

    protected $casts = ['furnished' => 'boolean', 'amenities' => 'array'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    // Le séjour à la nuit en cours, s'il y en a un.
    public function currentStay(): HasOne
    {
        return $this->hasOne(Stay::class)->current()->latest('check_in_at');
    }

    // Seul un logement meublé avec un tarif journalier se loue à la nuit.
    public function rentsByNight(): bool
    {
        return $this->furnished && (float) $this->daily_rate > 0;
    }

    public function scopeRentableByNight(Builder $query): Builder
    {
        return $query->where('furnished', true)->where('daily_rate', '>', 0);
    }

    public function currentLease(): HasOne
    {
        return $this->hasOne(Lease::class)->active()->latest('start_date');
    }

    // Libre, ou occupé par un locataire qui a donné son préavis : le suivant peut déjà être enregistré.
    public function acceptsNewTenant(): bool
    {
        return ! $this->currentLease || $this->currentLease->onNotice();
    }

    public function scopeOccupied(Builder $query): Builder
    {
        return $query->whereHas('leases', fn (Builder $lease) => $lease->active());
    }

    public function scopeVacant(Builder $query): Builder
    {
        return $query->whereDoesntHave('leases', fn (Builder $lease) => $lease->active());
    }

    public function typeLabel(): string
    {
        return self::typeName($this->type);
    }

    public static function typeName(string $type): string
    {
        return match ($type) {
            'room' => __('Chambre'),
            'studio' => __('Studio'),
            'house' => __('Maison'),
            'shop' => __('Local commercial'),
            default => __('Appartement'),
        };
    }

    // « Résidence A, n° 12 », ou seulement le nom quand le bien est un logement seul.
    public function label(): string
    {
        return $this->property->isSingle() ? $this->property->name : $this->property->name.__(', n° ').$this->number;
    }

    // « 2 chambres · 1 salon · 65 m² · Meublé » : uniquement ce qui est renseigné.
    public function summary(): string
    {
        return collect([
            $this->bedroomsLabel(),
            $this->living_rooms ? ($this->living_rooms > 1 ? __(':v1 salons', ['v1' => $this->living_rooms]) : __(':v1 salon', ['v1' => $this->living_rooms])) : null,
            $this->kitchens ? ($this->kitchens > 1 ? __(':v1 cuisines', ['v1' => $this->kitchens]) : __(':v1 cuisine', ['v1' => $this->kitchens])) : null,
            $this->bathrooms ? ($this->bathrooms > 1 ? __(':v1 douches', ['v1' => $this->bathrooms]) : __(':v1 douche', ['v1' => $this->bathrooms])) : null,
            $this->surface ? qty($this->surface).' m²' : null,
            $this->furnished ? __('Meublé') : null,
        ])->filter()->implode(' · ');
    }

    public function bedroomsLabel(): ?string
    {
        if (! $this->bedrooms) {
            return null;
        }

        return $this->bedrooms > 1 ? __(':v1 chambres', ['v1' => $this->bedrooms]) : __(':v1 chambre', ['v1' => $this->bedrooms]);
    }

    public function amenityLabels(): array
    {
        return collect(config('units.amenities'))
            ->only($this->amenities ?? [])
            ->map(fn (string $label) => __($label))
            ->values()->all();
    }
}
