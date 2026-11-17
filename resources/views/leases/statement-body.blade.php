{{-- Le détail d'un bail, repris dans son état et dans l'historique complet du locataire. --}}
<table class="invoice-lines statement-lines">
    <thead><tr><th class="left">{{ __('Mois') }}</th><th>{{ __('Échéance') }}</th><th>{{ __('Montant') }}</th><th>{{ __('Versé') }}</th><th>{{ __('Reste') }}</th></tr></thead>
    <tbody>
        @forelse ($invoices as $invoice)
            <tr>
                <td class="left">{{ $invoice->label() }}@if ($invoice->isOverdue()) ({{ __('en retard') }})@elseif ($invoice->isPrepaid()) ({{ __('payé d\'avance') }})@endif</td>
                <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                <td>{{ money($invoice->amount) }}</td>
                <td>{{ money($invoice->paid_amount) }}</td>
                <td>{{ money($invoice->balance()) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="left">{{ __('Aucune facture sur cette période.') }}</td></tr>
        @endforelse
    </tbody>
</table>

<table class="invoice-totals">
    <tr><th>{{ __('Total facturé') }}</th><td>{{ money($billed) }}</td></tr>
    <tr class="subtotal"><th>{{ __('Total versé') }}</th><td>{{ money($paid) }}</td></tr>
    <tr class="grand-total"><th>{{ __('Total des impayés') }}</th><td>{{ money($unpaid) }}</td></tr>
    @if ($overdue > 0)
        <tr><th>{{ __('Dont en retard') }}</th><td>{{ money($overdue) }}</td></tr>
    @endif
</table>

@if ($lease->paidThrough())
    <p class="invoice-note"><strong>{{ __('Loyer payé jusqu\'au :v1', ['v1' => $lease->paidThrough()->format('d/m/Y')]) }}</strong></p>
@endif

<h3 class="statement-title">{{ __('Versements') }}</h3>
@if ($payments->isEmpty())
    <p class="invoice-note">{{ __('Aucun versement sur cette période.') }}</p>
@else
    <table class="invoice-lines statement-lines">
        <thead><tr><th class="left">{{ __('Date') }}</th><th class="left">{{ __('Mode') }}</th><th class="left">{{ __('Référence') }}</th><th class="left">{{ __('Mois réglés') }}</th><th>{{ __('Montant') }}</th></tr></thead>
        <tbody>
            @foreach ($payments as $payment)
                <tr>
                    <td class="left">{{ $payment->date->format('d/m/Y') }}</td>
                    <td class="left">{{ $payment->method ?: '—' }}</td>
                    <td class="left">{{ $payment->reference ?: '—' }}</td>
                    <td class="left">{{ $payment->months }}</td>
                    <td>{{ money($payment->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (($refunds ?? collect())->isNotEmpty())
    <h3 class="statement-title">{{ __('Loyers remboursés') }}</h3>
    <table class="invoice-lines statement-lines">
        <thead><tr><th class="left">{{ __('Date') }}</th><th class="left">{{ __('Détail') }}</th><th>{{ __('Montant') }}</th></tr></thead>
        <tbody>
            @foreach ($refunds as $refund)
                <tr><td class="left">{{ $refund->refunded_on->format('d/m/Y') }}</td><td class="left">{{ $refund->note }}</td><td>{{ money($refund->amount) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <p class="invoice-note">{{ __('Ces mois étaient payés après la sortie : leur montant a été rendu au locataire. Les versements ci-dessus ne comptent que ce qui est resté sur les loyers.') }}</p>
@endif

<h3 class="statement-title">{{ __('Caution') }}</h3>
@include('leases.deposit-trace', ['tableClass' => 'invoice-lines statement-lines', 'noteClass' => 'invoice-note'])
<p class="invoice-note">{{ __('Encore détenue : :v1. La caution ne compte ni dans les loyers ni dans les versements ci-dessus.', ['v1' => money($lease->depositHeld())]) }}</p>
