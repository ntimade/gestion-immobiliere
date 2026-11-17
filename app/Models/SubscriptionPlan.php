<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['price', 'currency', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'price' => 'float'];

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('price')->orderBy('months');
    }

    public function isAvailable(): bool
    {
        return $this->is_active && $this->price !== null;
    }

    public function label(): string
    {
        return self::labelFor($this->months);
    }

    public static function labelFor(int $months): string
    {
        return match ($months) {
            1 => __('Mensuel'),
            3 => __('Trimestriel'),
            6 => __('Semestriel'),
            12 => __('Annuel'),
            default => __(':v1 mois', ['v1' => $months]),
        };
    }

    public function priceLabel(): string
    {
        return self::formatAmount($this->price, $this->currency);
    }

    public static function formatAmount(?float $amount, ?string $currency): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format($amount, (int) config("currencies.{$currency}.1", 2), ',', ' ').' '.$currency;
    }
}
