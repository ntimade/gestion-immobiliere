<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class Lease extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'unit_id', 'tenant_id', 'start_date', 'first_period', 'due_day', 'billing_months', 'rent', 'deposit_amount', 'deposit_months', 'deposit_paid_at',
        'end_date', 'deposit_retained', 'deposit_refunded', 'deposit_note', 'notes',
    ];

    // Les formulaires envoient du texte : ces nombres restent des nombres, même juste après la création.
    protected $casts = ['due_day' => 'integer', 'billing_months' => 'integer', 'deposit_months' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'deposit_paid_at' => 'date', 'legal_notice_opened_at' => 'datetime', 'exit_notice_opened_at' => 'datetime',
        'notice_given_on' => 'date', 'notice_end_date' => 'date', 'notice_opened_at' => 'datetime',
        'inspection_at' => 'datetime', 'inspection_opened_at' => 'datetime'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Un bail reste en cours jusqu'à son dernier jour : le logement est libre le lendemain. Le dernier jour est la
     * sortie, ou à défaut le départ annoncé par le préavis.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $lease) => $lease
            ->where(fn (Builder $open) => $open->whereNull('leases.end_date')->whereNull('leases.notice_end_date'))
            ->orWhere('leases.end_date', '>=', today()->toDateString())
            ->orWhere(fn (Builder $notice) => $notice->whereNull('leases.end_date')->where('leases.notice_end_date', '>=', today()->toDateString())));
    }

    public function isActive(): bool
    {
        $end = $this->effectiveEnd();

        return $end === null || $end->gte(today());
    }

    // Le dernier jour du bail : la sortie enregistrée, sinon le départ prévu par le préavis.
    public function effectiveEnd(): ?Carbon
    {
        return $this->end_date ?? $this->notice_end_date;
    }

    // Ce qui reste à payer jusqu'au départ : les mois impayés, plus ceux du préavis pas encore facturés.
    public function dueUntilDeparture(): float
    {
        $end = $this->effectiveEnd();
        $due = $this->unpaidTotal();

        if (! $end) {
            return $due;
        }

        $last = $this->invoices()->max('period');
        $period = $last ? self::followingPeriod($last) : $this->first_period;
        for ($months = 0; $period <= $end->format('Y-m'); $months++) {
            $period = self::followingPeriod($period);
        }

        return round($due + $months * (float) $this->rent, 3);
    }

    // Préavis donné, sortie pas encore finalisée (caution, état des lieux).
    public function onNotice(): bool
    {
        return $this->notice_end_date !== null && $this->end_date === null;
    }

    // Le départ prévu est passé : il reste à enregistrer la sortie et la caution.
    public function exitToFinalize(): bool
    {
        return $this->onNotice() && $this->notice_end_date->lt(today());
    }

    /**
     * L'échéance d'un mois : le jour choisi, ou le dernier jour des mois plus courts.
     * Le premier mois ne tombe jamais avant l'entrée du locataire.
     */
    public function dueDateFor(string $period): Carbon
    {
        $month = Carbon::createFromFormat('!Y-m', $period);
        $due = $month->copy()->day(min($this->due_day, $month->daysInMonth));

        return $due->lt($this->start_date) ? $this->start_date->copy() : $due;
    }

    public function depositHeld(): float
    {
        return round((float) $this->deposit_amount - (float) $this->deposit_retained - (float) $this->deposit_refunded, 3);
    }

    public function billingLabel(): string
    {
        return match ((int) $this->billing_months) {
            1 => __('Mensuelle'),
            3 => __('Trimestrielle'),
            6 => __('Semestrielle'),
            12 => __('Annuelle'),
            default => __('Tous les :v1 mois', ['v1' => $this->billing_months]),
        };
    }

    /**
     * Le dernier jour couvert par les mois entièrement réglés, à la veille de l'échéance suivante.
     * Null tant qu'aucun mois n'est réglé. Une avance terminée ne change rien : les mois suivants se paient un par un.
     */
    public function paidThrough(): ?Carbon
    {
        $lastPaid = null;
        $next = null;

        foreach ($this->invoices()->orderBy('period')->get() as $invoice) {
            if ($invoice->balance() > 0) {
                $next = $invoice;
                break;
            }
            $lastPaid = $invoice;
        }

        if (! $lastPaid) {
            return null;
        }

        // Dans un cycle, les mois partagent une échéance : la couverture reste comptée mois par mois.
        $date = ($next
            ? $next->due_date->copy()->max($this->dueDateFor($next->period))
            : $this->dueDateFor(self::followingPeriod($lastPaid->period)))->copy()->subDay();

        $end = $this->effectiveEnd();

        return $end && $date->gt($end) ? $end->copy() : $date;
    }

    // La prochaine échéance à payer. Null si le bail est terminé et que tout est réglé.
    public function nextDueDate(): ?Carbon
    {
        $unpaid = $this->invoices()->unpaid()->orderBy('period')->first();

        if ($unpaid) {
            return $unpaid->due_date;
        }

        $last = $this->invoices()->max('period');
        $next = $last ? self::followingPeriod($last) : $this->first_period;
        $end = $this->effectiveEnd();

        // Plus rien à payer après la sortie ou le départ prévu.
        return $end && $next > $end->format('Y-m') ? null : $this->dueDateFor($next);
    }

    private static function followingPeriod(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->addMonthNoOverflow()->format('Y-m');
    }

    public function rentRefunds(): HasMany
    {
        return $this->hasMany(RentRefund::class)->active()->orderBy('refunded_on')->orderBy('id');
    }

    // La part de la caution affectée aux loyers impayés à la sortie.
    public function depositToRent(): float
    {
        return round((float) $this->payments()->where('source', Payment::FROM_DEPOSIT)->sum('amount'), 3);
    }

    public function depositMovements(): HasMany
    {
        return $this->hasMany(DepositMovement::class)->orderBy('id');
    }

    // La date du dernier remboursement enregistré. Null si rien n'a été rendu.
    public function depositRefundedOn(): ?Carbon
    {
        return (float) $this->deposit_refunded > 0
            ? $this->depositMovements()->where('type', DepositMovement::REFUNDED)->reorder('id', 'desc')->first()?->happened_on
            : null;
    }

    public function recordDeposit(string $type, float $amount, Carbon|string $date, ?string $note = null): void
    {
        $this->depositMovements()->create([
            'organisation_id' => $this->organisation_id,
            'user_id' => Auth::id(),
            'type' => $type,
            'amount' => $amount,
            'happened_on' => $date,
            'note' => $note,
        ]);
    }

    // La caution en une ligne, pour les factures et les messages.
    public function depositSummary(): string
    {
        if ((float) $this->deposit_amount <= 0) {
            return __('Caution : aucune enregistrée');
        }

        $amount = money($this->deposit_amount).($this->deposit_months ? ' ('.__(':v1 mois de loyer', ['v1' => $this->deposit_months]).')' : '');

        $parts = [$this->deposit_paid_at
            ? __('Caution : :v1, versée le :v2', ['v1' => $amount, 'v2' => $this->deposit_paid_at->format('d/m/Y')])
            : __('Caution : :v1, date de versement non renseignée', ['v1' => $amount])];

        if ((float) $this->deposit_retained > 0) {
            $parts[] = __('retenue : :v1', ['v1' => money($this->deposit_retained)]);
        }

        if ((float) $this->deposit_refunded > 0) {
            $parts[] = __('rendue : :v1', ['v1' => money($this->deposit_refunded)]);
        }

        $parts[] = __('encore détenue : :v1', ['v1' => money($this->depositHeld())]);

        return implode(', ', $parts);
    }

    public function unpaidTotal(): float
    {
        return round((float) $this->invoices()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'), 3);
    }
}
