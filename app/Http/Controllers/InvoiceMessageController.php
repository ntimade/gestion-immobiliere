<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\LegalNotice;
use App\Support\InvoiceMessage;
use App\Support\PhoneNumber;
use App\Support\SortedTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class InvoiceMessageController extends Controller
{
    // Un paiement plus ancien n'attend plus de confirmation.
    public const PAYMENT_DAYS = 30;

    private const STATES = ['rappels', 'factures', 'paiements', 'retards', 'procedure'];

    private const RELATIONS = ['lease.tenant', 'lease.unit.property'];

    public function index(Request $request): View
    {
        $state = in_array($request->query('etat'), self::STATES, true) ? $request->query('etat') : self::STATES[0];
        $period = (string) $request->query('period');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = now()->format('Y-m');
        }

        $lateRows = self::lateRows();
        $legalRows = app(LegalNotice::class)->rows();

        return view('messages.index', [
            'state' => $state,
            'period' => $period,
            'reminderDays' => self::reminderDays(),
            'legalDelay' => auth()->user()->organisation->legalDelayLabel(),
            'counts' => [
                'rappels' => self::remindersToSend()->count(),
                'factures' => self::invoicesToSend()->count(),
                'paiements' => self::paymentsToConfirm()->count(),
                'retards' => $lateRows->filter(fn ($row) => $row->waiting)->count(),
                'procedure' => $legalRows->filter(fn ($row) => $row->waiting)->count(),
            ],
            'legal' => $state === 'procedure'
                ? SortedTable::collection($legalRows, 'procedure', [
                    'tenant' => fn ($row) => mb_strtolower($row->lease->tenant->name),
                    'total' => fn ($row) => $row->total,
                    'due' => fn ($row) => $row->oldest_due,
                ])
                : null,
            'reminders' => $state === 'rappels'
                ? SortedTable::query(self::remindersToSend()->with(self::RELATIONS)->orderBy('due_date')->orderBy('id'), 'rappels', ['amount' => 'amount', 'due' => 'due_date'])
                : null,
            'unreachable' => $state === 'rappels'
                ? Invoice::with(self::RELATIONS)->dueWithin(self::reminderDays())->firstOfCycle()->unreachable()->orderBy('due_date')->get()
                : collect(),
            'invoices' => $state === 'factures'
                ? SortedTable::query(self::invoicesToSend()->with(self::RELATIONS)->orderBy('due_date')->orderBy('id'), 'envois', ['amount' => 'amount', 'due' => 'due_date'])
                : null,
            'payments' => $state === 'paiements'
                ? SortedTable::query(self::paymentsToConfirm()->with(['lease.tenant', 'lease.unit.property', 'allocations.invoice'])->latest('paid_at')->latest('id'), 'confirmation', ['date' => 'paid_at', 'amount' => 'amount'])
                : null,
            'late' => $state === 'retards'
                ? SortedTable::collection($lateRows, 'retards', [
                    'tenant' => fn ($row) => mb_strtolower($row->lease->tenant->name),
                    'total' => fn ($row) => $row->total,
                    'due' => fn ($row) => $row->oldest_due,
                ])
                : null,
        ]);
    }

    /**
     * Les loyers dont l'échéance arrive sous quelques jours et dont le rappel n'est pas encore
     * parti. Toute la période compte, pas seulement le 3e jour : un jour sans connexion ne fait
     * pas perdre un rappel. Un mois payé d'avance n'y figure jamais.
     */
    public static function remindersToSend(): Builder
    {
        return Invoice::dueWithin(self::reminderDays())->firstOfCycle()->reachable()->whereNull('reminder_opened_at');
    }

    private static function invoicesToSend(): Builder
    {
        return Invoice::unpaid()->firstOfCycle()->reachable()
            ->where('due_date', '>=', today()->toDateString())
            ->whereNull('whatsapp_opened_at')->whereNull('sms_opened_at');
    }

    private static function paymentsToConfirm(): Builder
    {
        // Une retenue sur caution n'est pas un paiement à confirmer : le décompte de sortie l'annonce.
        return Payment::query()
            ->whereNull('source')
            ->whereNull('whatsapp_opened_at')->whereNull('sms_opened_at')
            ->where('created_at', '>=', now()->subDays(self::PAYMENT_DAYS))
            ->whereHas('lease.tenant', fn (Builder $tenant) => $tenant->where('phone', 'like', '+%'));
    }

    // Une ligne par bail en retard. Une relance attend tant qu'un mois échu n'a pas été relancé.
    private static function lateRows(): Collection
    {
        return Invoice::with(self::RELATIONS)->overdue()->orderBy('due_date')->get()
            ->groupBy('lease_id')
            ->map(fn (Collection $invoices) => (object) [
                'lease' => $invoices->first()->lease,
                'invoices' => $invoices,
                'total' => $invoices->sum(fn (Invoice $invoice) => $invoice->balance()),
                'oldest_due' => $invoices->first()->due_date->toDateString(),
                'waiting' => PhoneNumber::isInternational($invoices->first()->lease->tenant->phone)
                    && $invoices->contains(fn (Invoice $invoice) => ! $invoice->late_notice_opened_at),
                'opened_at' => $invoices->pluck('late_notice_opened_at')->filter()->max(),
            ])
            ->sortByDesc('total')->values();
    }

    // Le nombre affiché dans le menu : ce qui attend un envoi aujourd'hui.
    public static function waitingCount(): int
    {
        return self::remindersToSend()->count()
            + self::paymentsToConfirm()->count()
            + self::lateRows()->filter(fn ($row) => $row->waiting)->count()
            + app(LegalNotice::class)->rows()->filter(fn ($row) => $row->waiting)->count();
    }

    public function openReminder(Invoice $invoice, string $channel): Response
    {
        $invoice->load(self::RELATIONS);

        if (! $invoice->canBeSent()) {
            return back()->with('error', __('Ce loyer est déjà réglé.'));
        }

        if (! PhoneNumber::isInternational($invoice->lease->tenant->phone)) {
            return back()->with('error', __('Le numéro de ce locataire est absent ou à corriger.'));
        }

        $invoice->markCycle('reminder_opened_at');

        return InvoiceMessage::open(InvoiceMessage::reminderLink($invoice, $channel));
    }

    public function openPayment(Payment $payment, string $channel): Response
    {
        $payment->load(['lease.tenant', 'lease.unit.property', 'allocations.invoice']);

        if (! PhoneNumber::isInternational($payment->lease->tenant->phone)) {
            return back()->with('error', __('Le numéro de ce locataire est absent ou à corriger.'));
        }

        $payment->forceFill([$channel.'_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::paymentLink($payment, $channel));
    }

    public function openLateNotice(Lease $lease, string $channel): Response|RedirectResponse
    {
        $lease->load(['tenant', 'unit.property']);
        $invoices = Invoice::where('lease_id', $lease->id)->overdue()->orderBy('period')->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', __('Ce locataire n\'a plus de loyer en retard.'));
        }

        if (! PhoneNumber::isInternational($lease->tenant->phone)) {
            return back()->with('error', __('Le numéro de ce locataire est absent ou à corriger.'));
        }

        Invoice::whereKey($invoices->modelKeys())->update(['late_notice_opened_at' => now()]);

        return InvoiceMessage::open(InvoiceMessage::lateNoticeLink($lease, $invoices, $channel));
    }

    // On note l'ouverture de l'application : on ne peut pas savoir si le message est parti.
    public function open(Invoice $invoice, string $channel): Response
    {
        $invoice->load(self::RELATIONS);

        if (! $invoice->canBeSent()) {
            return back()->with('error', __('Ce loyer est déjà réglé.'));
        }

        if (! PhoneNumber::isInternational($invoice->lease->tenant->phone)) {
            return back()->with('error', __('Le numéro de ce locataire est absent ou à corriger.'));
        }

        $invoice->markCycle($channel.'_opened_at');

        return InvoiceMessage::open($channel === 'sms' ? InvoiceMessage::smsLink($invoice) : InvoiceMessage::whatsappLink($invoice));
    }

    private static function reminderDays(): int
    {
        return (int) auth()->user()->organisation->reminder_days;
    }
}
