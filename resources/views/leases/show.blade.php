@extends('layouts.app')
@section('title', $lease->tenant->name)

@section('content')
    <div class="page-head">
        <h1><a href="{{ route('tenants.show', $lease->tenant) }}">{{ $lease->tenant->name }}</a></h1>
        <div class="actions">
            <a class="btn ghost" href="{{ route('leases.statement', $lease) }}">{{ __('État du bail') }}</a>
            <a class="btn ghost" href="{{ route('leases.edit', $lease) }}">{{ __('Modifier le bail') }}</a>
            @if ($lease->end_date)
                <form class="inline" method="POST" action="{{ route('leases.reopen', $lease) }}" data-confirm="{{ __('Annuler cette sortie ? La caution et les factures reprendront.') }}" onsubmit="return confirm(this.dataset.confirm)">
                    @csrf
                    <button class="btn ghost">{{ __('Annuler la sortie') }}</button>
                </form>
            @else
                <a class="btn danger" href="{{ route('leases.exit', $lease) }}">{{ __('Enregistrer la sortie') }}</a>
            @endif
        </div>
    </div>

    @error('end_date')<div class="flash error">{{ $message }}</div>@enderror

    @if ($justPaid && \App\Support\PhoneNumber::isInternational($lease->tenant->phone))
        <div class="flash info">
            {{ __('Confirmez ce paiement au locataire :') }}
            <div class="actions" style="margin-top: 8px">@include('messages.payment-send', ['payment' => $justPaid, 'small' => true])</div>
        </div>
    @endif

    <div class="card">
        <dl class="detail">
            <div><dt>{{ __('Logement') }}</dt><dd><a href="{{ route('units.show', $lease->unit) }}">{{ $lease->unit->label() }}</a></dd></div>
            <div><dt>{{ __('Téléphone') }}</dt><dd>{{ $lease->tenant->phone ?: '—' }}</dd></div>
            <div><dt>{{ __('Entrée') }}</dt><dd>{{ $lease->start_date->format('d/m/Y') }}</dd></div>
            <div><dt>{{ __('Sortie') }}</dt><dd>{{ $lease->end_date?->format('d/m/Y') ?? ($lease->onNotice() ? __('Préavis : départ le :v1', ['v1' => $lease->notice_end_date->format('d/m/Y')]) : '—') }}</dd></div>
            <div><dt>{{ __('Loyer par mois') }}</dt><dd>{{ money($lease->rent) }}</dd></div>
            <div><dt>{{ __('Échéance') }}</dt><dd>{{ __('Le :v1 de chaque mois', ['v1' => $lease->due_day]) }}</dd></div>
            <div><dt>{{ __('Facturation') }}</dt><dd>{{ $lease->billingLabel() }}</dd></div>
            <div><dt>{{ __('Reste à payer') }}</dt><dd @class(['danger-text' => $unpaid > 0])>{{ money($unpaid) }}</dd></div>
            <div><dt>{{ __('Payé jusqu\'au') }}</dt><dd>{{ $lease->paidThrough()?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt>{{ __('Prochaine échéance') }}</dt><dd>{{ $lease->nextDueDate()?->format('d/m/Y') ?? '—' }}</dd></div>
        </dl>
        @if ($lease->notes)<p class="soft small">{{ __('Observation : :v1', ['v1' => $lease->notes]) }}</p>@endif
    </div>

    @unless ($lease->end_date)
        @include('leases.notice-card')
    @endunless

    <div class="card">
        <h2>{{ __('Caution') }}</h2>
        <dl class="detail">
            <div><dt>{{ __('Montant') }}</dt><dd>{{ money($lease->deposit_amount) }}</dd></div>
            @if ($lease->deposit_months)<div><dt>{{ __('Durée') }}</dt><dd>{{ __(':v1 mois de loyer', ['v1' => $lease->deposit_months]) }}</dd></div>@endif
            <div><dt>{{ __('Versée le') }}</dt><dd>{{ $lease->deposit_paid_at?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt>{{ __('Retenue') }}</dt><dd>{{ money($lease->deposit_retained) }}</dd></div>
            <div><dt>{{ __('Rendue') }}</dt><dd>{{ money($lease->deposit_refunded) }}</dd></div>
            <div><dt>{{ __('Encore détenue') }}</dt><dd><strong>{{ money($lease->depositHeld()) }}</strong></dd></div>
        </dl>
        @if ($lease->deposit_note)<p class="soft small">{{ __('Justification : :v1', ['v1' => $lease->deposit_note]) }}</p>@endif
        @if ($lease->end_date)
            <div class="actions" style="margin: 10px 0">
                @if (\App\Support\PhoneNumber::isInternational($lease->tenant->phone))
                    <form class="inline" method="POST" action="{{ route('messages.exit', [$lease, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
                        @csrf
                        <button @class(['btn small', 'ghost' => $lease->exit_notice_opened_at])><x-icon name="send" /> {{ __('Décompte sur WhatsApp') }}</button>
                    </form>
                    <form class="inline" method="POST" action="{{ route('messages.exit', [$lease, 'sms']) }}" data-message-send>
                        @csrf
                        <button class="btn ghost small"><x-icon name="message-square" /> {{ __('Décompte par SMS') }}</button>
                    </form>
                @else
                    <a class="btn ghost small" href="{{ route('tenants.edit', $lease->tenant) }}">{{ __('Corriger le numéro') }}</a>
                @endif
            </div>
            @if ($lease->exit_notice_opened_at)<p class="soft small">{{ __('Décompte ouvert le :v1', ['v1' => $lease->exit_notice_opened_at->format('d/m/Y')]) }}</p>@endif
        @endif
        <h3>{{ __('Historique de la caution') }}</h3>
        <div class="table-wrap">@include('leases.deposit-trace', ['showUser' => true])</div>
    </div>

    <div class="card" id="encaisser">
        <h2>{{ __('Encaisser un paiement') }}</h2>
        <p class="soft small">{{ __('Le paiement règle d\'abord les mois les plus anciens. S\'il dépasse ce qui est dû, il couvre les mois suivants : c\'est une avance. L\'avance n\'est jamais obligatoire : une fois terminée, le loyer repasse au mois par mois.') }}</p>
        <p class="soft small">{{ __('La caution se verse une seule fois, à l\'entrée : ces paiements vont uniquement aux loyers.') }}</p>
        <div class="actions" style="margin-bottom: 12px">
            @if ($unpaid > 0)
                <button type="button" class="btn ghost small" data-fill-amount="{{ (float) $unpaid }}">{{ __('Tout le reste à payer') }}</button>
            @endif
            @unless ($lease->end_date)
                @foreach (collect([1, 3, 6, 12, (int) $lease->billing_months])->unique()->sort() as $months)
                    <button type="button" class="btn ghost small" data-fill-amount="{{ (float) $unpaid + $months * (float) $lease->rent }}">{{ $unpaid > 0 ? __('Reste + :v1 mois d\'avance', ['v1' => $months]) : __(':v1 mois d\'avance', ['v1' => $months]) }}</button>
                @endforeach
            @endunless
        </div>
        <form method="POST" action="{{ route('payments.store', $lease) }}">
            @csrf
            <div class="form-grid">
                <x-field name="amount" :label="__('Montant')" type="number" step="any" min="0" :required="true" />
                <x-field name="paid_at" :label="__('Date')" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" :required="true" />
            </div>
            <div class="form-grid">
                <x-field name="method" :label="__('Mode de paiement')" :placeholder="__('Espèces, Mobile Money…')" />
                <x-field name="reference" :label="__('Référence')" />
            </div>
            <button type="submit" class="btn">{{ __('Enregistrer le paiement') }}</button>
        </form>
    </div>

    @if ($lease->rentRefunds->isNotEmpty())
        <div class="card">
            <h2>{{ __('Loyers remboursés') }}</h2>
            <div class="table-wrap"><table>
                <tr><th>{{ __('Date') }}</th><th>{{ __('Détail') }}</th><th class="right">{{ __('Montant') }}</th></tr>
                @foreach ($lease->rentRefunds as $refund)
                    <tr><td>{{ $refund->refunded_on->format('d/m/Y') }}</td><td>{{ $refund->note }}</td><td class="right">{{ money($refund->amount) }}</td></tr>
                @endforeach
            </table></div>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Factures de loyer') }}</h2>
        @if ($invoices->isEmpty())
            <p class="empty">{{ __('Aucune facture pour le moment : la première sera créée pour :v1.', ['v1' => period_label($lease->first_period)]) }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-factures">
                <tr><x-sort-th :table="$invoices" key="period" kind="date">{{ __('Mois') }}</x-sort-th><x-sort-th :table="$invoices" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><x-sort-th :table="$invoices" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><x-sort-th :table="$invoices" key="paid" kind="number" class="right">{{ __('Payé') }}</x-sort-th><th>{{ __('État') }}</th><th></th></tr>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->label() }}</a></td>
                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                        <td class="right">{{ money($invoice->amount) }}</td>
                        <td class="right">{{ money($invoice->paid_amount) }}</td>
                        <td>@include('invoices.status', ['invoice' => $invoice])</td>
                        <td>
                            <div class="actions">
                                @if (\App\Support\PhoneNumber::isInternational($lease->tenant->phone))
                                    @include('messages.send', ['invoice' => $invoice, 'small' => true])
                                @endif
                                <a class="btn ghost small" href="{{ route('invoices.edit', $invoice) }}">{{ __('Corriger') }}</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
            {{ $invoices->links() }}
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Paiements') }}</h2>
        @if ($payments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table id="t-paiements">
                <tr><x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th><th>{{ __('Mois réglés') }}</th><x-sort-th :table="$payments" key="method">{{ __('Mode') }}</x-sort-th><x-sort-th :table="$payments" key="reference">{{ __('Référence') }}</x-sort-th><x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                        <td>{{ $payment->coveredLabel() }}</td>
                        <td>{{ $payment->method ?: '—' }}</td>
                        <td>{{ $payment->reference ?: '—' }}</td>
                        <td class="right">{{ money($payment->amount) }}</td>
                        <td>
                            <div class="actions">
                                @include('messages.payment-send', ['payment' => $payment, 'small' => true])
                                <a class="btn ghost small" href="{{ route('payments.edit', $payment) }}">{{ __('Corriger') }}</a>
                                <form class="inline" method="POST" action="{{ route('payments.destroy', $payment) }}" data-confirm="{{ __('Annuler ce paiement ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf @method('DELETE')
                                    <button class="btn danger small">{{ __('Annuler') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
            {{ $payments->links() }}
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-fill-amount]').forEach(function (button) {
            button.addEventListener('click', function () {
                var amount = document.getElementById('amount');
                amount.value = button.dataset.fillAmount;
                amount.focus();
            });
        });
    </script>
@endpush
