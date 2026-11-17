<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use BelongsToOrganisation;

    protected $fillable = ['organisation_id', 'lease_id', 'period', 'due_date', 'amount', 'paid_amount', 'notes'];

    protected $casts = [
        'due_date' => 'date',
        'whatsapp_opened_at' => 'datetime',
        'sms_opened_at' => 'datetime',
        'reminder_opened_at' => 'datetime',
        'late_notice_opened_at' => 'datetime',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereColumn('paid_amount', '<', 'amount');
    }

    // En retard à partir du lendemain de l'échéance.
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->unpaid()->where('due_date', '<', today()->toDateString());
    }

    // Loyers à échéance aujourd'hui ou dans les prochains jours.
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        // Borne de fin exclue : le résultat ne dépend pas du format de date de la base.
        return $query->unpaid()
            ->where('due_date', '>=', today()->toDateString())
            ->where('due_date', '<', today()->addDays($days + 1)->toDateString());
    }

    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereHas('lease.tenant', fn (Builder $tenant) => $tenant->where('phone', 'like', '+%'));
    }

    public function scopeUnreachable(Builder $query): Builder
    {
        return $query->whereDoesntHave('lease.tenant', fn (Builder $tenant) => $tenant->where('phone', 'like', '+%'));
    }

    /**
     * Une seule ligne par échéance dans les listes d'envoi : les mois d'un même cycle partagent
     * leur échéance et partent dans un seul message. Sans effet sur un bail au mois.
     */
    public function scopeFirstOfCycle(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($earlier) => $earlier->select(DB::raw(1))
            ->from('invoices as earlier')
            ->whereColumn('earlier.lease_id', 'invoices.lease_id')
            ->whereColumn('earlier.due_date', 'invoices.due_date')
            ->whereColumn('earlier.period', '<', 'invoices.period')
            ->whereColumn('earlier.paid_amount', '<', 'earlier.amount'));
    }

    // Les mois encore dus de ce cycle, celui-ci compris.
    public function cycleMonths(): Collection
    {
        return self::unpaid()->where('lease_id', $this->lease_id)->whereDate('due_date', $this->due_date)->orderBy('period')->get();
    }

    public function cycleBalance(): float
    {
        return round((float) $this->cycleMonths()->sum(fn (Invoice $month) => $month->balance()), 3);
    }

    public function cycleLabel(): string
    {
        $months = $this->cycleMonths();

        return $months->count() > 1
            ? __(':v1 à :v2', ['v1' => $months->first()->label(), 'v2' => $months->last()->label()])
            : $this->label();
    }

    // Note l'envoi sur tous les mois de l'échéance : un même message les couvre.
    public function markCycle(string $column): void
    {
        self::where('lease_id', $this->lease_id)->whereDate('due_date', $this->due_date)->update([$column => now()]);
    }

    public function number(): string
    {
        return 'LOY-'.str_replace('-', '', $this->period).'-'.$this->id;
    }

    public function label(): string
    {
        return period_label($this->period);
    }

    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 3);
    }

    // Tous les mois encore dus sur ce bail, du plus ancien au plus récent, celui-ci compris.
    public function unpaidMonths(): Collection
    {
        return self::unpaid()->where('lease_id', $this->lease_id)->orderBy('period')->get();
    }

    // Un loyer réglé n'a plus aucune facture ni rappel à envoyer : seule la confirmation de paiement reste.
    public function canBeSent(): bool
    {
        return $this->balance() > 0;
    }

    public function isOverdue(): bool
    {
        return $this->balance() > 0 && $this->due_date->lt(today());
    }

    // Réglée alors que son échéance n'est pas encore arrivée.
    public function isPrepaid(): bool
    {
        return $this->balance() <= 0 && (float) $this->amount > 0 && $this->due_date->gt(today());
    }

    public function statusKey(): string
    {
        return match (true) {
            $this->isPrepaid() => 'prepaid',
            $this->balance() <= 0 => 'paid',
            $this->isOverdue() => 'late',
            (float) $this->paid_amount > 0 => 'partial',
            default => 'open',
        };
    }
}
