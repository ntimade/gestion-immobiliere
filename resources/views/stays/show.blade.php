@extends('layouts.app')
@section('title', __('Séjour :v1', ['v1' => $stay->number()]))

@section('content')
    <div class="page-head">
        <h1>{{ __('Séjour :v1', ['v1' => $stay->number()]) }}</h1>
        <div class="actions">
            <a class="btn" href="{{ route('stays.invoice', $stay) }}"><x-icon name="receipt" /> {{ __('Imprimer la facture') }}</a>
            <a class="btn ghost" href="{{ route('stays.invoice.pdf', $stay) }}" download><x-icon name="download" /> {{ __('PDF') }}</a>
        </div>
    </div>

    <div class="card">
        <h2>{{ __('Message de remerciement') }}</h2>
        @if (\App\Support\PhoneNumber::isInternational($stay->guest->phone))
            <p class="soft small">{{ __('Le message reprend la facture et l\'heure de libération du logement : le client le garde comme facture.') }}</p>
            <div class="actions">
                <form class="inline" method="POST" action="{{ route('stays.message', [$stay, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
                    @csrf
                    <button @class(['btn', 'ghost' => $stay->whatsapp_opened_at])><x-icon name="send" /> {{ __('Envoyer sur WhatsApp') }}</button>
                </form>
                <form class="inline" method="POST" action="{{ route('stays.message', [$stay, 'sms']) }}" data-message-send>
                    @csrf
                    <button class="btn ghost"><x-icon name="message-square" /> {{ __('Envoyer par SMS') }}</button>
                </form>
            </div>
            @if ($stay->whatsapp_opened_at)<p class="soft small">{{ __('WhatsApp ouvert le :v1.', ['v1' => $stay->whatsapp_opened_at->format('d/m/Y H:i')]) }}</p>@endif
        @else
            <p class="soft">{{ __('Le numéro de ce client est absent ou à corriger : le message ne peut pas être préparé.') }}</p>
        @endif
    </div>

    <div class="card">
        <dl class="detail">
            <div><dt>{{ __('Client') }}</dt><dd>{{ $stay->guest->title() }} {{ $stay->guest->name }}</dd></div>
            <div><dt>{{ __('Téléphone') }}</dt><dd>{{ $stay->guest->phone ?: '—' }}</dd></div>
            @if ($stay->guest->id_number)<div><dt>{{ __('N° de pièce d\'identité') }}</dt><dd>{{ $stay->guest->id_number }}</dd></div>@endif
            <div><dt>{{ __('Logement') }}</dt><dd><a href="{{ route('units.show', $stay->unit) }}">{{ $stay->unit->label() }}</a></dd></div>
            <div><dt>{{ __('Arrivée') }}</dt><dd>{{ __('le :v1 à :v2', ['v1' => $stay->check_in_at->format('d/m/Y'), 'v2' => $stay->check_in_at->format('H:i')]) }}</dd></div>
            <div><dt>{{ __('Durée') }}</dt><dd>{{ $stay->daysLabel() }} ({{ 24 * $stay->days }} h)</dd></div>
            <div><dt>{{ __('Libération du logement') }}</dt><dd><strong>{{ __('le :v1 à :v2', ['v1' => $stay->check_out_at->format('d/m/Y'), 'v2' => $stay->check_out_at->format('H:i')]) }}</strong>@if ($stay->inProgress()) <span class="badge ok">{{ __('En cours') }}</span>@endif</dd></div>
            <div><dt>{{ __('Tarif journalier (24 h)') }}</dt><dd>{{ money($stay->daily_rate) }}</dd></div>
            <div><dt>{{ __('Montant du séjour') }}</dt><dd>{{ money($stay->amount) }}</dd></div>
            <div><dt>{{ __('Payé') }}</dt><dd>{{ money($stay->paid_amount) }}</dd></div>
            <div><dt>{{ __('Reste à payer') }}</dt><dd>@if ($stay->balance() > 0)<span class="danger-text">{{ money($stay->balance()) }}</span>@else{{ money(0) }}@endif</dd></div>
            @if ($stay->method)<div><dt>{{ __('Mode de paiement') }}</dt><dd>{{ $stay->method }}</dd></div>@endif
            @if ($stay->reference)<div><dt>{{ __('Référence') }}</dt><dd>{{ $stay->reference }}</dd></div>@endif
            @if ($stay->notes)<div><dt>{{ __('Observation') }}</dt><dd>{{ $stay->notes }}</dd></div>@endif
            <div><dt>{{ __('Enregistré par') }}</dt><dd>{{ $stay->user?->name ?? '—' }}, {{ $stay->created_at->format('d/m/Y H:i') }}</dd></div>
        </dl>
    </div>

    @if ($stay->balance() > 0)
        <div class="card">
            <h2>{{ __('Ajouter un paiement') }}</h2>
            <form method="POST" action="{{ route('stays.pay', $stay) }}">
                @csrf
                <div class="form-grid">
                    <x-field name="amount" :label="__('Montant')" type="number" step="any" min="0" :required="true" :value="$stay->balance()" />
                    <x-field name="method" :label="__('Mode de paiement')" :placeholder="__('Espèces, Mobile Money…')" />
                </div>
                <x-field name="reference" :label="__('Référence')" />
                <button type="submit" class="btn">{{ __('Enregistrer le paiement') }}</button>
            </form>
        </div>
    @endif

    <p><a class="btn ghost" href="{{ route('stays.index', ['guest_id' => $stay->guest_id]) }}"><x-icon name="clipboard-list" /> {{ __('Tous les séjours de ce client') }}</a></p>
@endsection
