<?php

namespace App\Services;

use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RentRefund;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentService
{
    // Limite de sécurité : une faute de frappe ne doit pas créer des dizaines d'années de factures.
    public const MAX_MONTHS_AHEAD = 60;

    /**
     * Installe un locataire dans un logement libre. Le verrou empêche deux entrées simultanées.
     *
     * @param  array{start_date: string, first_period?: ?string, due_day: int, rent: float|string, deposit_amount?: float|string|null, deposit_paid_at?: ?string, notes?: ?string}  $data
     */
    public function startLease(Unit $unit, Tenant $tenant, array $data): Lease
    {
        return DB::transaction(function () use ($unit, $tenant, $data) {
            Unit::whereKey($unit->id)->lockForUpdate()->firstOrFail();

            // Un locataire en préavis n'empêche pas d'enregistrer le suivant, à partir du lendemain de son départ.
            if ($unit->leases()->whereNull('end_date')->whereNull('notice_end_date')->exists()) {
                $this->fail('unit_id', __('Ce logement est déjà occupé. Enregistrez d\'abord la sortie de son locataire.'));
            }

            $previousEnd = $unit->leases()->selectRaw('MAX(COALESCE(end_date, notice_end_date)) as last_day')->value('last_day');
            if ($previousEnd && $data['start_date'] <= substr((string) $previousEnd, 0, 10)) {
                $this->fail('start_date', __('L\'entrée doit être postérieure à la sortie du locataire précédent (:v1).', ['v1' => Carbon::parse($previousEnd)->format('d/m/Y')]));
            }

            $lease = Lease::create([
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'start_date' => $data['start_date'],
                'first_period' => $data['first_period'] ?? Carbon::parse($data['start_date'])->format('Y-m'),
                'due_day' => $data['due_day'],
                'billing_months' => $data['billing_months'] ?? 1,
                'rent' => $data['rent'],
                'deposit_amount' => $data['deposit_amount'] ?? 0,
                'deposit_months' => $data['deposit_months'] ?? null,
                'deposit_paid_at' => $data['deposit_paid_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ((float) $lease->deposit_amount > 0) {
                $lease->recordDeposit(
                    DepositMovement::PAID,
                    (float) $lease->deposit_amount,
                    $data['deposit_paid_at'] ?? $data['start_date'],
                    $lease->deposit_months ? __(':v1 mois de loyer', ['v1' => $lease->deposit_months]) : null,
                );
            }

            // Avec une avance à l'entrée, le premier cycle se forme après les mois qu'elle couvre.
            if (empty($data['entry_advance'])) {
                $this->syncLease($lease);
            }

            return $lease;
        });
    }

    /**
     * Le nouveau loyer et le nouveau jour d'échéance valent pour les prochaines factures :
     * celles qui existent gardent leur montant et leur date, sauf correction une par une.
     */
    public function updateLease(Lease $lease, array $data): void
    {
        DB::transaction(function () use ($lease, $data) {
            $locked = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();

            if ((float) $data['deposit_amount'] < (float) $locked->deposit_retained + (float) $locked->deposit_refunded) {
                $this->fail('deposit_amount', __('La caution ne peut pas être inférieure à ce qui a déjà été retenu ou rendu.'));
            }

            // La date d'entrée se corrige pour régulariser un client. Les factures existantes ne changent pas.
            if (isset($data['start_date'])) {
                $this->assertStartDate($locked, $data['start_date']);
            }

            $previousAmount = (float) $locked->deposit_amount;
            $previousDate = $locked->deposit_paid_at?->toDateString();

            $locked->update($data);

            $change = round((float) $locked->deposit_amount - $previousAmount, 3);
            $date = $locked->deposit_paid_at?->toDateString();

            if ($change != 0 || $date !== $previousDate) {
                $locked->recordDeposit(DepositMovement::ADJUSTED, $change, today(), $date !== $previousDate
                    ? __('Date de versement : :v1', ['v1' => $locked->deposit_paid_at?->format('d/m/Y') ?? '—'])
                    : null);
            }
        });
    }

    /**
     * Le préavis : le locataire annonce son départ. La facturation s'arrête après le mois du départ et le
     * logement se libère le lendemain ; la caution attend l'état des lieux et la sortie.
     */
    public function giveNotice(Lease $lease, string $givenOn, string $endDate): void
    {
        DB::transaction(function () use ($lease, $givenOn, $endDate) {
            $locked = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();

            if ($locked->end_date) {
                $this->fail('notice_end_date', __('La sortie de ce locataire est déjà enregistrée.'));
            }

            if ($endDate < $givenOn) {
                $this->fail('notice_end_date', __('Le départ ne peut pas précéder le préavis.'));
            }

            if ($endDate < $locked->start_date->toDateString()) {
                $this->fail('notice_end_date', __('Le départ ne peut pas être antérieur à l\'entrée (:v1).', ['v1' => $locked->start_date->format('d/m/Y')]));
            }

            $next = Lease::where('unit_id', $locked->unit_id)->whereKeyNot($locked->id)->where('start_date', '>', $locked->start_date->toDateString())->min('start_date');
            if ($next && $endDate >= substr((string) $next, 0, 10)) {
                $this->fail('notice_end_date', __('Le locataire suivant entre le :v1 : le départ doit être antérieur.', ['v1' => Carbon::parse($next)->format('d/m/Y')]));
            }

            // Les mois à venir non payés disparaissent ; ceux déjà payés seront remboursés à la sortie.
            $locked->invoices()->where('period', '>', Carbon::parse($endDate)->format('Y-m'))->where('paid_amount', 0)->delete();
            $locked->forceFill(['notice_given_on' => $givenOn, 'notice_end_date' => $endDate, 'notice_opened_at' => null])->save();
        });
    }

    // Le locataire reste finalement : la facturation reprend là où elle s'était arrêtée.
    public function cancelNotice(Lease $lease): void
    {
        DB::transaction(function () use ($lease) {
            $locked = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();

            if ($locked->end_date) {
                $this->fail('notice_end_date', __('La sortie est déjà enregistrée : annulez-la plutôt.'));
            }

            if (Lease::where('unit_id', $locked->unit_id)->whereKeyNot($locked->id)->where('start_date', '>', $locked->start_date->toDateString())->exists()) {
                $this->fail('notice_end_date', __('Un nouveau locataire est déjà enregistré dans ce logement : le préavis ne peut plus être annulé.'));
            }

            $locked->forceFill([
                'notice_given_on' => null, 'notice_end_date' => null, 'notice_opened_at' => null,
                'inspection_at' => null, 'inspection_report' => null, 'inspection_cost' => null, 'inspection_opened_at' => null,
            ])->save();
            $this->syncLease($locked);
        });
    }

    // L'état des lieux de sortie : sa date, puis ce qui a été constaté et le coût des réfections.
    public function recordInspection(Lease $lease, array $data): void
    {
        $lease->forceFill(array_intersect_key($data, array_flip(['inspection_at', 'inspection_report', 'inspection_cost'])))->save();
    }

    private function assertStartDate(Lease $lease, string $start): void
    {
        if (substr($start, 0, 7) > $lease->first_period) {
            $this->fail('start_date', __('L\'entrée ne peut pas être postérieure au premier mois facturé (:v1).', ['v1' => period_label($lease->first_period)]));
        }

        if ($lease->end_date && $start > $lease->end_date->toDateString()) {
            $this->fail('start_date', __('L\'entrée ne peut pas être postérieure à la sortie (:v1).', ['v1' => $lease->end_date->format('d/m/Y')]));
        }

        $previousEnd = Lease::where('unit_id', $lease->unit_id)->whereKeyNot($lease->id)
            ->where('start_date', '<', $lease->start_date->toDateString())
            ->selectRaw('MAX(COALESCE(end_date, notice_end_date)) as last_day')->value('last_day');

        if ($previousEnd && $start <= substr((string) $previousEnd, 0, 10)) {
            $this->fail('start_date', __('L\'entrée doit être postérieure à la sortie du locataire précédent (:v1).', ['v1' => Carbon::parse($previousEnd)->format('d/m/Y')]));
        }
    }

    /**
     * Enregistre la sortie et ce qui devient la caution. Les loyers impayés restent dus, sauf la part de la
     * retenue que le bailleur leur affecte. Les mois déjà payés après la sortie sont remboursés au locataire :
     * le paiement d'origine reste tel quel, le remboursement est noté à côté.
     *
     * @param  array{end_date: string, deposit_retained?: float|string|null, deposit_refunded?: float|string|null, deposit_note?: ?string}  $data
     */
    public function endLease(Lease $lease, array $data): void
    {
        DB::transaction(function () use ($lease, $data) {
            $locked = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $endPeriod = Carbon::parse($data['end_date'])->format('Y-m');
            $retained = (float) ($data['deposit_retained'] ?? 0);
            $refunded = (float) ($data['deposit_refunded'] ?? 0);

            if ($data['end_date'] < $locked->start_date->toDateString()) {
                $this->fail('end_date', __('La sortie ne peut pas être antérieure à l\'entrée (:v1).', ['v1' => $locked->start_date->format('d/m/Y')]));
            }

            if (round($retained + $refunded, 3) > (float) $locked->deposit_amount) {
                $this->fail('deposit_refunded', __('La somme retenue et rendue dépasse la caution (:v1).', ['v1' => money($locked->deposit_amount)]));
            }

            // Toute somme gardée sur la caution se justifie : le motif reste dans son historique.
            $note = trim((string) ($data['deposit_note'] ?? ''));
            $partial = $retained > 0 || ($refunded > 0 && round($refunded, 3) < (float) $locked->deposit_amount);
            if ($partial && $note === '') {
                $this->fail('deposit_note', __('Justifiez ce qui n\'est pas remboursé sur la caution.'));
            }

            $toRent = round((float) ($data['deposit_to_rent'] ?? 0), 3);
            if ($toRent > round($retained, 3)) {
                $this->fail('deposit_to_rent', __('La part affectée aux loyers ne peut pas dépasser la retenue (:v1).', ['v1' => money($retained)]));
            }

            $refundDate = $data['deposit_refunded_on'] ?? $data['end_date'];

            // Les mois payés après la sortie : leur montant est rendu au locataire, puis ces mois disparaissent.
            $paidAfter = $locked->invoices()->where('period', '>', $endPeriod)->where('paid_amount', '>', 0)->orderBy('period')->get();
            if ($paidAfter->isNotEmpty()) {
                RentRefund::create([
                    'organisation_id' => $locked->organisation_id,
                    'lease_id' => $locked->id,
                    'user_id' => Auth::id(),
                    'amount' => round((float) $paidAfter->sum('paid_amount'), 3),
                    'refunded_on' => $refundDate,
                    'note' => __('Loyers payés après la sortie : :v1', ['v1' => $paidAfter->map(fn (Invoice $invoice) => $invoice->label())->implode(', ')]),
                ]);
            }

            PaymentAllocation::whereIn('payment_id', Payment::withTrashed()->where('lease_id', $locked->id)->select('id'))->delete();
            $locked->invoices()->where('period', '>', $endPeriod)->delete();
            $locked->forceFill(['end_date' => $data['end_date']])->save();
            $this->reallocate($locked);

            if ($toRent > 0 && $toRent > $locked->unpaidTotal()) {
                $this->fail('deposit_to_rent', __('La part affectée aux loyers ne peut pas dépasser les loyers impayés (:v1).', ['v1' => money($locked->unpaidTotal())]));
            }

            $locked->update([
                'end_date' => $data['end_date'],
                'deposit_retained' => $retained,
                'deposit_refunded' => $refunded,
                'deposit_note' => $note !== '' ? $note : null,
            ]);

            if ($retained > 0) {
                $locked->recordDeposit(DepositMovement::RETAINED, -$retained, $data['end_date'], $toRent > 0
                    ? $note.' — '.__('dont :v1 affectés aux loyers impayés', ['v1' => money($toRent)])
                    : $note);
            }

            if ($refunded > 0) {
                $locked->recordDeposit(DepositMovement::REFUNDED, -$refunded, $refundDate, $retained > 0 ? __('Remboursement partiel') : null);
            }

            // La part de la retenue qui règle des loyers : un paiement à part, reconnaissable, que l'annulation de la sortie retire.
            if ($toRent > 0) {
                Payment::create([
                    'organisation_id' => $locked->organisation_id,
                    'lease_id' => $locked->id,
                    'user_id' => Auth::id(),
                    'amount' => $toRent,
                    'paid_at' => $data['end_date'],
                    'method' => __('Retenue sur caution'),
                    'source' => Payment::FROM_DEPOSIT,
                ]);
                $this->reallocate($locked);
            }
        });
    }

    // Annule une sortie saisie par erreur, si le logement n'a pas été reloué entre-temps.
    public function reopenLease(Lease $lease): void
    {
        DB::transaction(function () use ($lease) {
            Unit::whereKey($lease->unit_id)->lockForUpdate()->firstOrFail();
            $locked = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();

            if (Lease::where('unit_id', $locked->unit_id)->whereKeyNot($locked->id)->where('start_date', '>', $locked->start_date)->exists()) {
                $this->fail('end_date', __('Ce logement a été reloué depuis : cette sortie ne peut plus être annulée.'));
            }

            // La retenue et le remboursement annulés restent lisibles dans l'historique de la caution.
            $restored = round((float) $locked->deposit_retained + (float) $locked->deposit_refunded, 3);
            if ($restored > 0) {
                $locked->recordDeposit(DepositMovement::EXIT_CANCELLED, $restored, today());
            }

            $locked->update(['end_date' => null, 'deposit_retained' => 0, 'deposit_refunded' => 0, 'deposit_note' => null]);
            $locked->forceFill(['exit_notice_opened_at' => null])->save();

            // Le remboursement des loyers est annulé (et non effacé), la part de caution affectée aux loyers est retirée.
            RentRefund::where('lease_id', $locked->id)->active()->update(['cancelled_at' => now()]);
            Payment::where('lease_id', $locked->id)->where('source', Payment::FROM_DEPOSIT)->get()->each->delete();
            $this->reallocate($locked);
            $this->syncLease($locked);
        });
    }

    // Crée les factures qui manquent sur tous les baux du compte.
    public function syncOrganisation(Organisation $organisation): void
    {
        Lease::withoutGlobalScope('organisation')->where('organisation_id', $organisation->id)
            ->active()->orderBy('id')->get()
            ->each(fn (Lease $lease) => $this->syncLease($lease));

        $organisation->forceFill(['invoices_synced_on' => today()->toDateString()])->save();
    }

    /**
     * Crée les factures dues d'un bail : jusqu'au mois en cours, et le mois suivant dès que
     * son échéance entre dans la période de rappel. Chaque échéance porte un cycle entier : un mois
     * pour un bail mensuel, trois pour un bail trimestriel. Le cycle part du premier mois qui
     * n'est pas encore facturé : il reprend donc après les mois payés d'avance.
     */
    public function syncLease(Lease $lease): void
    {
        DB::transaction(function () use ($lease) {
            $locked = Lease::withoutGlobalScope('organisation')->whereKey($lease->id)->lockForUpdate()->firstOrFail();
            // Rien n'est facturé après la sortie, ni après le départ annoncé par le préavis.
            $endPeriod = $locked->effectiveEnd()?->format('Y-m');
            $currentPeriod = now()->format('Y-m');
            $horizon = $this->horizon($locked);
            $period = $this->nextPeriod($locked);

            while (($endPeriod === null || $period <= $endPeriod)
                && ($period <= $currentPeriod || $locked->dueDateFor($period)->lte($horizon))) {
                $due = $locked->dueDateFor($period);

                for ($month = 0; $month < max(1, (int) $locked->billing_months) && ($endPeriod === null || $period <= $endPeriod); $month++) {
                    $this->createInvoice($locked, $period, $due);
                    $period = $this->followingPeriod($period);
                }
            }
        });
    }

    public function recordPayment(Lease $lease, float $amount, string $paidAt, ?string $method, ?string $reference): Payment
    {
        return DB::transaction(function () use ($lease, $amount, $paidAt, $method, $reference) {
            $locked = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrencyDecimals($locked, $amount);

            $payment = Payment::create([
                'organisation_id' => $locked->organisation_id,
                'lease_id' => $locked->id,
                'user_id' => Auth::id(),
                'amount' => $amount,
                'paid_at' => $paidAt,
                'method' => $method,
                'reference' => $reference,
            ]);

            $this->reallocate($locked);

            return $payment->fresh(['allocations.invoice']);
        });
    }

    public function updatePayment(Payment $payment, float $amount, string $paidAt, ?string $method, ?string $reference): void
    {
        DB::transaction(function () use ($payment, $amount, $paidAt, $method, $reference) {
            $locked = Lease::whereKey($payment->lease_id)->lockForUpdate()->firstOrFail();
            $this->assertCurrencyDecimals($locked, $amount);

            $payment->update(['amount' => $amount, 'paid_at' => $paidAt, 'method' => $method, 'reference' => $reference]);

            $this->reallocate($locked);
        });
    }

    public function cancelPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $locked = Lease::whereKey($payment->lease_id)->lockForUpdate()->firstOrFail();

            $payment->delete();

            $this->reallocate($locked);
        });
    }

    // Corrige une facture. Si elle baisse sous ce qui est payé, le surplus passe au mois suivant.
    public function correctInvoice(Invoice $invoice, array $data): void
    {
        DB::transaction(function () use ($invoice, $data) {
            $locked = Lease::whereKey($invoice->lease_id)->lockForUpdate()->firstOrFail();
            $this->assertCurrencyDecimals($locked, (float) $data['amount'], 'amount');

            $invoice->update($data);

            $this->reallocate($locked);
        });
    }

    /**
     * Répartit tous les paiements du bail du mois le plus ancien au plus récent, et crée les
     * mois à venir que couvre une avance. Tout recalculer à chaque changement évite qu'annuler
     * un ancien paiement laisse un mois impayé au milieu de mois réglés.
     */
    private function reallocate(Lease $lease): void
    {
        $invoices = $lease->invoices()->orderBy('period')->lockForUpdate()->get()->values();
        $payments = $lease->payments()->orderBy('paid_at')->orderBy('id')->lockForUpdate()->get();

        PaymentAllocation::whereIn('payment_id', Payment::withTrashed()->where('lease_id', $lease->id)->select('id'))->delete();

        // Les loyers remboursés à la sortie se retirent des derniers paiements : ce qui reste règle les mois.
        $refunded = round((float) RentRefund::withoutGlobalScope('organisation')->where('lease_id', $lease->id)->active()->sum('amount'), 3);
        $effective = [];
        foreach ($payments->reverse() as $payment) {
            $cut = min($refunded, (float) $payment->amount);
            $effective[$payment->id] = round((float) $payment->amount - $cut, 3);
            $refunded = round($refunded - $cut, 3);
        }

        $paid = [];
        $index = 0;

        foreach ($payments as $payment) {
            $rest = $effective[$payment->id];

            while ($rest > 0) {
                if ($index >= $invoices->count()) {
                    $invoices->push($this->createAdvanceInvoice($lease, $rest));
                }

                $invoice = $invoices[$index];
                $room = round((float) $invoice->amount - ($paid[$invoice->id] ?? 0), 3);

                if ($room <= 0) {
                    $index++;

                    continue;
                }

                $share = min($rest, $room);
                PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $share]);
                $paid[$invoice->id] = round(($paid[$invoice->id] ?? 0) + $share, 3);
                $rest = round($rest - $share, 3);
            }
        }

        $currentPeriod = now()->format('Y-m');
        $horizon = $this->horizon($lease);

        foreach ($invoices as $invoice) {
            $amount = $paid[$invoice->id] ?? 0;

            // Un mois à venir qui n'est plus payé d'avance disparaît : il reviendra le moment venu.
            if ($amount <= 0 && $invoice->period > $currentPeriod && $invoice->due_date->gt($horizon)) {
                $invoice->delete();

                continue;
            }

            if ((float) $invoice->paid_amount !== (float) $amount) {
                $invoice->update(['paid_amount' => $amount]);
            }
        }
    }

    private function createAdvanceInvoice(Lease $lease, float $rest): Invoice
    {
        $period = $this->nextPeriod($lease);

        if ($lease->effectiveEnd() && $period > $lease->effectiveEnd()->format('Y-m')) {
            $this->fail('amount', __('Ce bail est terminé : :v1 dépassent ce qu\'il reste à payer.', ['v1' => money($rest)]));
        }

        if ($period > now()->addMonthsNoOverflow(self::MAX_MONTHS_AHEAD)->format('Y-m')) {
            $this->fail('amount', __('Une avance ne peut pas couvrir plus de :v1 mois.', ['v1' => self::MAX_MONTHS_AHEAD]));
        }

        if ((float) $lease->rent <= 0) {
            $this->fail('amount', __('Ce bail n\'a pas de loyer : aucune avance n\'est possible.'));
        }

        return $this->createInvoice($lease, $period);
    }

    private function createInvoice(Lease $lease, string $period, ?Carbon $due = null): Invoice
    {
        return Invoice::withoutGlobalScope('organisation')->create([
            'organisation_id' => $lease->organisation_id,
            'lease_id' => $lease->id,
            'period' => $period,
            'due_date' => ($due ?? $lease->dueDateFor($period))->toDateString(),
            'amount' => $lease->rent,
        ]);
    }

    private function nextPeriod(Lease $lease): string
    {
        $last = Invoice::withoutGlobalScope('organisation')->where('lease_id', $lease->id)->max('period');

        return $last ? $this->followingPeriod($last) : $lease->first_period;
    }

    private function followingPeriod(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->addMonthNoOverflow()->format('Y-m');
    }

    // Les factures du mois suivant existent dès que leur rappel doit partir.
    private function horizon(Lease $lease): Carbon
    {
        $days = (int) Organisation::whereKey($lease->organisation_id)->value('reminder_days');

        return today()->addDays($days);
    }

    private function assertCurrencyDecimals(Lease $lease, float $amount, string $field = 'amount'): void
    {
        $decimals = Organisation::whereKey($lease->organisation_id)->firstOrFail()->currencyDecimals();

        if (round($amount, $decimals) !== round($amount, 3)) {
            $this->fail($field, $decimals === 0
                ? __('Cette devise n\'accepte pas de décimales.')
                : __('Cette devise accepte :v1 décimales au maximum.', ['v1' => $decimals]));
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
