<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PaymentAllocation;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Collection;

class PropertyFigures
{
    public const HISTORY_MONTHS = 6;

    /**
     * Revenu mensuel de tous les biens du compte.
     *
     * @return array{current: float, estimated: float, shortfall: float}
     */
    public function totals(): array
    {
        $properties = Property::select('properties.id')->withIncome()->get();
        $current = (float) $properties->sum('current_income');
        $estimated = (float) $properties->sum('estimated_income');

        return ['current' => $current, 'estimated' => $estimated, 'shortfall' => max(0, round($estimated - $current, 3))];
    }

    // Le détail affiché sur la fiche d'un bien.
    public function forProperty(Property $property): array
    {
        $units = $property->units()->with('currentLease')->get();
        $leases = Lease::whereIn('unit_id', $units->modelKeys())->select('id');
        $invoices = fn () => Invoice::whereIn('lease_id', $leases);
        $period = now()->format('Y-m');

        $current = (float) $units->sum(fn (Unit $unit) => (float) $unit->currentLease?->rent);
        $estimated = (float) $units->sum(fn (Unit $unit) => (float) ($unit->currentLease?->rent ?? $unit->rent));
        $occupied = $units->filter(fn (Unit $unit) => $unit->currentLease)->count();
        $month = $invoices()->where('period', $period)->selectRaw('COALESCE(SUM(amount), 0) as billed, COALESCE(SUM(paid_amount), 0) as paid')->first();

        return [
            'unitsCount' => $units->count(),
            'occupied' => $occupied,
            'vacant' => $units->count() - $occupied,
            'occupancy' => $units->isEmpty() ? 0 : (int) round($occupied / $units->count() * 100),
            'current' => $current,
            'estimated' => $estimated,
            'shortfall' => max(0, round($estimated - $current, 3)),
            'yearly' => $current * 12,
            'billed' => (float) $month->billed,
            'collected' => (float) PaymentAllocation::whereIn('invoice_id', $invoices()->select('id'))
                ->whereHas('payment', fn ($payment) => $payment
                    ->where('paid_at', '>=', now()->startOfMonth()->toDateString())
                    ->where('paid_at', '<', now()->startOfMonth()->addMonthNoOverflow()->toDateString()))
                ->sum('amount'),
            'overdue' => (float) $invoices()->overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'deposits' => (float) Lease::whereIn('unit_id', $units->modelKeys())
                ->selectRaw('COALESCE(SUM(deposit_amount - deposit_retained - deposit_refunded), 0) as total')->value('total'),
            'types' => $this->byType($units),
            'history' => $this->history(fn () => $invoices()),
        ];
    }

    // Une ligne par configuration : « Appartement · 3 chambres » et « Appartement · 2 chambres » sont distincts.
    private function byType(Collection $units): Collection
    {
        return $units
            ->sortBy(fn (Unit $unit) => [array_search($unit->type, Unit::TYPES, true), (int) $unit->bedrooms])
            ->groupBy(fn (Unit $unit) => $unit->type.'|'.(int) $unit->bedrooms)
            ->map(function (Collection $rows) {
                $first = $rows->first();

                return (object) [
                    'label' => collect([$first->typeLabel(), $first->bedroomsLabel()])->filter()->implode(' · '),
                    'total' => $rows->count(),
                    'occupied' => $rows->filter(fn (Unit $unit) => $unit->currentLease)->count(),
                    // Un meublé loué seulement à la nuit (loyer à 0) ne fausse pas le loyer le plus bas.
                    'minRent' => (float) $rows->filter(fn (Unit $unit) => (float) $unit->rent > 0)->min(fn (Unit $unit) => (float) $unit->rent),
                    'maxRent' => (float) $rows->max(fn (Unit $unit) => (float) $unit->rent),
                    'current' => (float) $rows->sum(fn (Unit $unit) => (float) $unit->currentLease?->rent),
                    'estimated' => (float) $rows->sum(fn (Unit $unit) => (float) ($unit->currentLease?->rent ?? $unit->rent)),
                ];
            })
            ->values();
    }

    // Ce qui a été facturé et payé pour chacun des derniers mois, y compris les mois sans facture.
    private function history(callable $invoices): Collection
    {
        $first = now()->startOfMonth()->subMonthsNoOverflow(self::HISTORY_MONTHS - 1)->format('Y-m');

        $rows = $invoices()->where('period', '>=', $first)->where('period', '<=', now()->format('Y-m'))
            ->selectRaw('period, COALESCE(SUM(amount), 0) as billed, COALESCE(SUM(paid_amount), 0) as paid')
            ->groupBy('period')->get()->keyBy('period');

        return collect(range(0, self::HISTORY_MONTHS - 1))->map(function (int $back) use ($rows) {
            $period = now()->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m');
            $billed = (float) ($rows[$period]->billed ?? 0);
            $paid = (float) ($rows[$period]->paid ?? 0);

            return (object) ['period' => $period, 'billed' => $billed, 'paid' => $paid, 'rest' => max(0, round($billed - $paid, 3))];
        });
    }
}
