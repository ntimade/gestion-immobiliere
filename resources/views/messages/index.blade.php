@extends('layouts.app')
@section('title', __('Messages aux locataires'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Messages aux locataires') }}</h1>
        <x-info-button>{{ __('Chaque bouton ouvre WhatsApp ou les SMS avec le message déjà écrit : il ne reste qu\'à appuyer sur Envoyer. Pour une facture, le PDF se télécharge en même temps : joignez-le avec le trombone. Le bouton SMS ouvre la messagerie sur la conversation du locataire et le SMS part de votre forfait.') }}</x-info-button>
    </div>

    @unless (config('messaging.enabled'))
        <div class="flash info">{{ __('L\'envoi des messages WhatsApp et SMS est désactivé : les boutons sont grisés. Les listes, les factures et les lettres restent disponibles.') }}</div>
    @endunless

    <nav class="steps" aria-label="{{ __('Étapes') }}">
        @foreach ([
            ['rappels', '1', __('Rappels à envoyer'), __(':v1 jours avant l\'échéance', ['v1' => $reminderDays])],
            ['factures', '2', __('Factures à envoyer'), __('Loyers pas encore payés')],
            ['paiements', '3', __('Confirmations de paiement'), __('Après chaque paiement')],
            ['retards', '4', __('Relances'), __('Après l\'échéance')],
            ['procedure', '5', __('Procédure judiciaire'), $legalDelay ? __('Après :v1 d\'impayé', ['v1' => $legalDelay]) : __('Délai à régler')],
        ] as [$key, $number, $label, $hint])
            <a href="{{ route('messages.index', ['etat' => $key]) }}" @class(['step', 'active' => $state === $key]) @if ($state === $key) aria-current="page" @endif>
                <span class="step-number">{{ $number }}</span>
                <span class="step-text"><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>
                <span @class(['step-count', 'empty-count' => ! $counts[$key]])>{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($state === 'rappels')
        <p class="soft small">{{ __('Les loyers à échéance d\'ici :v1 jours, sans rappel envoyé. Un mois payé d\'avance n\'apparaît pas ici. Une ligne disparaît dès que vous ouvrez le message.', ['v1' => $reminderDays]) }}</p>
        <div class="card">
            @if ($reminders->isEmpty())
                <p class="empty">{{ __('Aucun rappel à envoyer aujourd\'hui.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-rappels">
                    <tr><th>{{ __('Locataire') }}</th><th>{{ __('Téléphone') }}</th><th>{{ __('Logement') }}</th><x-sort-th :table="$reminders" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><x-sort-th :table="$reminders" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                    @foreach ($reminders as $invoice)
                        <tr>
                            <td><a href="{{ route('leases.show', $invoice->lease_id) }}">{{ $invoice->lease->tenant->name }}</a></td>
                            <td>{{ $invoice->lease->tenant->phone }}</td>
                            <td>{{ $invoice->lease->unit->label() }}</td>
                            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td class="right">{{ money($invoice->cycleBalance()) }}</td>
                            <td><div class="actions">@include('messages.reminder-send', ['invoice' => $invoice])</div></td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $reminders->links() }}
            @endif
        </div>

        @if ($unreachable->isNotEmpty())
            <div class="card">
                <h2>{{ __('Rappels impossibles : numéro absent ou à corriger') }}</h2>
                <div class="table-wrap cards-wide"><table>
                    <tr><th>{{ __('Locataire') }}</th><th>{{ __('Logement') }}</th><th>{{ __('Échéance') }}</th><th></th></tr>
                    @foreach ($unreachable as $invoice)
                        <tr>
                            <td>{{ $invoice->lease->tenant->name }}</td>
                            <td>{{ $invoice->lease->unit->label() }}</td>
                            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td><div class="actions"><a class="btn ghost small" href="{{ route('tenants.edit', $invoice->lease->tenant) }}">{{ __('Corriger le numéro') }}</a></div></td>
                        </tr>
                    @endforeach
                </table></div>
            </div>
        @endif
    @elseif ($state === 'factures')
        <div class="card">
            @if ($invoices->isEmpty())
                <p class="empty">{{ __('Toutes les factures en cours ont été envoyées.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-envois">
                    <tr><th>{{ __('Locataire') }}</th><th>{{ __('Logement') }}</th><th>{{ __('Mois') }}</th><x-sort-th :table="$invoices" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><x-sort-th :table="$invoices" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('leases.show', $invoice->lease_id) }}">{{ $invoice->lease->tenant->name }}</a></td>
                            <td>{{ $invoice->lease->unit->label() }}</td>
                            <td>{{ $invoice->cycleLabel() }}</td>
                            <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td class="right">{{ money($invoice->cycleBalance()) }}</td>
                            <td><div class="actions">@include('messages.send', ['invoice' => $invoice, 'small' => true])</div></td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $invoices->links() }}
            @endif
        </div>
    @elseif ($state === 'paiements')
        <p class="soft small">{{ __('Les paiements des :v1 derniers jours qui n\'ont pas encore été confirmés au locataire.', ['v1' => \App\Http\Controllers\InvoiceMessageController::PAYMENT_DAYS]) }}</p>
        <div class="card">
            @if ($payments->isEmpty())
                <p class="empty">{{ __('Tous les paiements récents ont été confirmés.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-confirmation">
                    <tr><x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th><th>{{ __('Locataire') }}</th><th>{{ __('Mois réglés') }}</th><x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th><th></th></tr>
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at->format('d/m/Y') }}</td>
                            <td><a href="{{ route('leases.show', $payment->lease_id) }}">{{ $payment->lease->tenant->name }}</a></td>
                            <td>{{ $payment->coveredLabel() }}</td>
                            <td class="right">{{ money($payment->amount) }}</td>
                            <td><div class="actions">@include('messages.payment-send', ['payment' => $payment, 'small' => true])</div></td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $payments->links() }}
            @endif
        </div>
    @elseif ($state === 'procedure')
        @if (! $legalDelay)
            <div class="card">
                <p class="empty">{{ __('Aucun délai n\'est réglé : aucune notification n\'est proposée pour le moment.') }}</p>
                @if (auth()->user()->isAdmin())
                    <div class="actions"><a class="btn" href="{{ route('settings.edit') }}">{{ __('Régler le délai') }}</a></div>
                @else
                    <p class="soft small">{{ __('Seul un administrateur du compte peut régler ce délai.') }}</p>
                @endif
            </div>
        @else
            <p class="soft small">{{ __('Les locataires dont l\'échéance impayée la plus ancienne est dépassée depuis :v1 ou plus. Le bouton WhatsApp ouvre le message et télécharge la lettre : joignez-la avec le trombone. Le bouton SMS ouvre la messagerie sur la conversation du locataire, avec le message déjà écrit.', ['v1' => $legalDelay]) }}</p>
            <p class="soft small">{{ __('Avant d\'envoyer, vérifiez les dates des antécédents : ce sont celles où chaque message a été ouvert depuis l\'application. Cette notification ne remplace pas les actes d\'un huissier ou d\'un avocat.') }}</p>
            <div class="card">
                @if ($legal->isEmpty())
                    <p class="empty">{{ __('Aucun locataire n\'a atteint ce délai.') }}</p>
                @else
                    <div class="table-wrap cards-wide"><table id="t-procedure">
                        <tr><x-sort-th :table="$legal" key="tenant">{{ __('Locataire') }}</x-sort-th><th>{{ __('Logement') }}</th><x-sort-th :table="$legal" key="due" kind="date">{{ __('Échéance la plus ancienne') }}</x-sort-th><x-sort-th :table="$legal" key="total" kind="number" class="right">{{ __('Total dû') }}</x-sort-th><th>{{ __('Antécédents') }}</th><th></th></tr>
                        @foreach ($legal as $row)
                            <tr>
                                <td><a href="{{ route('leases.show', $row->lease) }}">{{ $row->lease->tenant->name }}</a></td>
                                <td>{{ $row->lease->unit->label() }}</td>
                                <td>{{ \Illuminate\Support\Carbon::parse($row->oldest_due)->format('d/m/Y') }}</td>
                                <td class="right danger-text">{{ money($row->total) }}</td>
                                <td>
                                    @forelse ($row->history as $entry)
                                        <span class="soft small cell-line">{{ $entry->date->format('d/m/Y') }} : {{ $entry->label }} ({{ $entry->months }})</span>
                                    @empty
                                        <span class="danger-text small">{{ __('Aucun message précédent') }}</span>
                                    @endforelse
                                </td>
                                <td>
                                    <div class="actions">
                                        @if ($row->reachable)
                                            <form class="inline" method="POST" action="{{ route('messages.legal', [$row->lease, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send data-pdf-url="{{ route('messages.legal.letter', $row->lease) }}">
                                                @csrf
                                                <button @class(['btn small', 'ghost' => ! $row->waiting])><x-icon name="send" /> {{ __('WhatsApp') }}</button>
                                            </form>
                                            <form class="inline" method="POST" action="{{ route('messages.legal', [$row->lease, 'sms']) }}" data-message-send>
                                                @csrf
                                                <button class="btn ghost small"><x-icon name="message-square" /> {{ __('SMS') }}</button>
                                            </form>
                                        @else
                                            <a class="btn ghost small" href="{{ route('tenants.edit', $row->lease->tenant) }}">{{ __('Corriger le numéro') }}</a>
                                        @endif
                                        <a class="btn ghost small" href="{{ route('messages.legal.letter', $row->lease) }}" download data-pdf-share title="{{ __('Partager ou télécharger la lettre en PDF') }}"><x-icon name="receipt" /> {{ __('Lettre') }}</a>
                                    </div>
                                    @if ($row->opened_at)<span class="soft small cell-line">{{ __('Notification ouverte le :v1', ['v1' => $row->opened_at->format('d/m/Y')]) }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </table></div>
                    {{ $legal->links() }}
                @endif
            </div>
        @endif
    @else
        <p class="soft small">{{ __('Les locataires dont l\'échéance est dépassée. La relance reprend tous les mois en retard du bail.') }}</p>
        <div class="card">
            @if ($late->isEmpty())
                <p class="empty">{{ __('Aucun loyer en retard.') }}</p>
            @else
                <div class="table-wrap cards-wide"><table id="t-retards">
                    <tr><x-sort-th :table="$late" key="tenant">{{ __('Locataire') }}</x-sort-th><th>{{ __('Logement') }}</th><th>{{ __('Mois en retard') }}</th><x-sort-th :table="$late" key="total" kind="number" class="right">{{ __('Total dû') }}</x-sort-th><x-sort-th :table="$late" key="due" kind="date">{{ __('Échéance') }}</x-sort-th><th></th></tr>
                    @foreach ($late as $row)
                        <tr>
                            <td><a href="{{ route('leases.show', $row->lease) }}">{{ $row->lease->tenant->name }}</a></td>
                            <td>{{ $row->lease->unit->label() }}</td>
                            <td>{{ $row->invoices->count() }}</td>
                            <td class="right danger-text">{{ money($row->total) }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($row->oldest_due)->format('d/m/Y') }}</td>
                            <td>
                                <div class="actions">
                                    @if (\App\Support\PhoneNumber::isInternational($row->lease->tenant->phone))
                                        <form class="inline" method="POST" action="{{ route('messages.late', [$row->lease, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
                                            @csrf
                                            <button @class(['btn small', 'ghost' => ! $row->waiting])><x-icon name="send" /> {{ __('WhatsApp') }}</button>
                                        </form>
                                        <form class="inline" method="POST" action="{{ route('messages.late', [$row->lease, 'sms']) }}" data-message-send>
                                            @csrf
                                            <button class="btn ghost small"><x-icon name="message-square" /> {{ __('SMS') }}</button>
                                        </form>
                                    @else
                                        <a class="btn ghost small" href="{{ route('tenants.edit', $row->lease->tenant) }}">{{ __('Corriger le numéro') }}</a>
                                    @endif
                                </div>
                                @if ($row->opened_at)<span class="soft small cell-line">{{ __('Dernière relance ouverte le :v1', ['v1' => $row->opened_at->format('d/m/Y')]) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </table></div>
                {{ $late->links() }}
            @endif
        </div>
    @endif
@endsection
