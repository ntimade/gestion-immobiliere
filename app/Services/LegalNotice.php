<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organisation;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * La notification du début d'une procédure judiciaire. Un bail est concerné quand son échéance impayée
 * la plus ancienne dépasse le délai réglé par le bailleur. L'application prépare la lettre et le
 * message : c'est le bailleur qui décide de les envoyer et d'engager la procédure.
 */
class LegalNotice
{
    private const RELATIONS = ['lease.tenant', 'lease.unit.property'];

    public function rows(): Collection
    {
        $organisation = auth()->user()->organisation;

        if (! $organisation->legal_notice_after) {
            return collect();
        }

        return Invoice::with(self::RELATIONS)->overdue()->orderBy('due_date')->get()
            ->groupBy('lease_id')
            ->map(fn (Collection $invoices) => $this->row($organisation, $invoices))
            ->filter()
            ->sortByDesc('total')
            ->values();
    }

    public function forLease(Lease $lease): ?object
    {
        $invoices = Invoice::with(self::RELATIONS)->where('lease_id', $lease->id)->overdue()->orderBy('due_date')->get();

        return $invoices->isEmpty() ? null : $this->row(auth()->user()->organisation, $invoices);
    }

    public static function procedureSentence(Lease $lease): string
    {
        return $lease->isActive()
            ? __('Nous vous informons que nous engageons une procédure judiciaire pour le recouvrement de ces sommes et, le cas échéant, la résiliation du bail et votre expulsion du logement.')
            : __('Nous vous informons que nous engageons une procédure judiciaire pour le recouvrement de ces sommes.');
    }

    private function row(Organisation $organisation, Collection $invoices): ?object
    {
        $oldest = $invoices->first();
        $reachedOn = $organisation->legalNoticeDate($oldest->due_date);

        if (! $reachedOn || $reachedOn->gt(today())) {
            return null;
        }

        $lease = $oldest->lease;
        $reachable = PhoneNumber::isInternational($lease->tenant->phone);

        return (object) [
            'lease' => $lease,
            'reference' => 'PROC-'.today()->format('Ym').'-'.$lease->id,
            'invoices' => $invoices,
            'total' => round((float) $invoices->sum(fn (Invoice $invoice) => $invoice->balance()), 3),
            'oldest_due' => $oldest->due_date->toDateString(),
            'history' => $this->history($invoices),
            'reachable' => $reachable,
            // Une dette réglée puis revenue après la notification en demande une nouvelle.
            'waiting' => $reachable && (! $lease->legal_notice_opened_at || $lease->legal_notice_opened_at->lt($oldest->due_date)),
            'opened_at' => $lease->legal_notice_opened_at,
        ];
    }

    // Les antécédents : les messages déjà ouverts pour ces mois, un même jour et un même type sur une ligne.
    private function history(Collection $invoices): Collection
    {
        $entries = collect();

        foreach ($invoices as $invoice) {
            foreach ([
                ['reminder_opened_at', __('Rappel avant l\'échéance')],
                ['whatsapp_opened_at', __('Facture')],
                ['sms_opened_at', __('Facture')],
                ['late_notice_opened_at', __('Relance après l\'échéance')],
            ] as [$column, $label]) {
                if ($invoice->{$column}) {
                    $entries->push((object) ['date' => $invoice->{$column}, 'label' => $label, 'month' => $invoice->label()]);
                }
            }
        }

        return $entries
            ->groupBy(fn ($entry) => $entry->date->toDateString().'|'.$entry->label)
            ->map(fn (Collection $same) => (object) [
                'date' => $same->first()->date,
                'label' => $same->first()->label,
                'months' => $same->pluck('month')->unique()->implode(', '),
            ])
            ->sortBy(fn ($entry) => $entry->date->timestamp)
            ->values();
    }
}
