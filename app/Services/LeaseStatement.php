<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PaymentAllocation;
use Illuminate\Support\Collection;

/**
 * L'état d'un bail : ses mois, ce qui a été versé et ce qui reste dû. Par défaut, il va du premier
 * au dernier mois facturé. Les totaux portent sur les mois affichés : facturé - versé = impayé.
 */
class LeaseStatement
{
    public function build(Lease $lease, ?string $from = null, ?string $to = null): array
    {
        $lease->loadMissing(['tenant', 'unit.property']);

        $all = $lease->invoices()->orderBy('period')->get();
        $from = $this->validPeriod($from) ?? $all->first()?->period ?? $lease->first_period;
        $to = $this->validPeriod($to) ?? $all->last()?->period ?? $lease->first_period;

        // Mois inversés : on remet la période dans le bon ordre plutôt que d'afficher un état vide.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $invoices = $all->filter(fn (Invoice $invoice) => $invoice->period >= $from && $invoice->period <= $to)->values();

        return [
            'lease' => $lease,
            'from' => $from,
            'to' => $to,
            'invoices' => $invoices,
            'payments' => $this->payments($invoices),
            // Les loyers rendus à la sortie : le paiement d'origine n'est pas modifié, ils se lisent à part.
            'refunds' => $lease->rentRefunds()->get(),
            'billed' => round((float) $invoices->sum(fn (Invoice $invoice) => (float) $invoice->amount), 3),
            'paid' => round((float) $invoices->sum(fn (Invoice $invoice) => (float) $invoice->paid_amount), 3),
            'unpaid' => round((float) $invoices->sum(fn (Invoice $invoice) => $invoice->balance()), 3),
            'overdue' => round((float) $invoices->filter(fn (Invoice $invoice) => $invoice->isOverdue())->sum(fn (Invoice $invoice) => $invoice->balance()), 3),
            // Du premier au dernier jour du bail : la sortie si elle est connue, sinon aujourd'hui.
            'lastDay' => $lease->end_date ?? today(),
        ];
    }

    // Les versements sur ces mois. Une avance à cheval sur la période ne compte que pour sa part dans les mois affichés.
    private function payments(Collection $invoices): Collection
    {
        return PaymentAllocation::with(['payment', 'invoice'])
            ->whereIn('invoice_id', $invoices->modelKeys())
            ->get()
            ->groupBy('payment_id')
            ->map(function (Collection $shares) {
                $payment = $shares->first()->payment;

                return (object) [
                    'date' => $payment->paid_at,
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'months' => $shares->sortBy(fn (PaymentAllocation $share) => $share->invoice->period)->map(fn (PaymentAllocation $share) => $share->invoice->label())->implode(', '),
                    'amount' => round((float) $shares->sum(fn (PaymentAllocation $share) => (float) $share->amount), 3),
                ];
            })
            ->sortBy(fn ($row) => [$row->date->toDateString(), $row->id])
            ->values();
    }

    private function validPeriod(?string $period): ?string
    {
        return $period !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : null;
    }
}
