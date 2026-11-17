{{-- La facture d'un séjour à la nuit, à imprimer ou en PDF : même présentation que les factures de loyer. --}}
<article class="invoice">
    <header class="invoice-head">
        <div class="invoice-org">
            @if ($organisation->logo_path && $logoSrc)
                <img class="invoice-logo" src="{{ $logoSrc }}" alt="{{ __('Logo de :v1', ['v1' => $organisation->name]) }}">
            @endif
            <strong>{{ $organisation->name }}</strong>
            @if ($organisation->address)<span>{{ $organisation->address }}</span>@endif
            @if ($organisation->phone)<span>{{ __('Tél. : :v1', ['v1' => $organisation->phone]) }}</span>@endif
        </div>
        <div class="invoice-title">
            <h1>{{ __('Facture de séjour') }}</h1>
            <span class="invoice-number">{{ $stay->number() }}</span>
            <span>{{ __('Émise le :v1', ['v1' => $stay->created_at->format('d/m/Y')]) }}</span>
            <span>{{ __('Imprimée le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
        </div>
    </header>

    <section class="invoice-parties">
        <div>
            <h2>{{ __('Client') }}</h2>
            <strong>{{ $stay->guest->name }}</strong>
            @if ($stay->guest->phone)<span>{{ __('Tél. : :v1', ['v1' => $stay->guest->phone]) }}</span>@endif
            @if ($stay->guest->id_number)<span>{{ __('N° de pièce d\'identité') }} : {{ $stay->guest->id_number }}</span>@endif
        </div>
        <div>
            <h2>{{ __('Logement') }}</h2>
            <strong>{{ $stay->unit->label() }}</strong>
            <span>{{ $stay->unit->typeLabel() }} · {{ __('Meublé') }}</span>
            @if ($stay->unit->amenityLabels())<span>{{ implode(', ', $stay->unit->amenityLabels()) }}</span>@endif
            @if ($stay->unit->property->address)<span>{{ $stay->unit->property->address }}</span>@endif
        </div>
    </section>

    <table class="invoice-totals">
        <tr><th>{{ __('Arrivée') }}</th><td>{{ $stay->check_in_at->format('d/m/Y H:i') }}</td></tr>
        <tr><th>{{ __('Libération du logement') }}</th><td><strong>{{ $stay->check_out_at->format('d/m/Y H:i') }}</strong></td></tr>
        <tr><th>{{ __('Durée') }}</th><td>{{ $stay->daysLabel() }} ({{ 24 * $stay->days }} h)</td></tr>
        <tr><th>{{ __('Tarif journalier (24 h)') }}</th><td>{{ money($stay->daily_rate) }}</td></tr>
        <tr class="subtotal"><th>{{ __('Montant du séjour') }}</th><td>{{ money($stay->amount) }}</td></tr>
        <tr><th>{{ __('Payé') }}@if ($stay->method) ({{ $stay->method }})@endif</th><td>− {{ money($stay->paid_amount) }}</td></tr>
        <tr class="grand-total"><th>{{ __('Reste à payer') }}</th><td>{{ money($stay->balance()) }}</td></tr>
    </table>

    @if ($stay->reference)<p class="invoice-note">{{ __('Référence du paiement : :v1', ['v1' => $stay->reference]) }}</p>@endif
    @if ($stay->notes)<p class="invoice-note">{{ __('Observation : :v1', ['v1' => $stay->notes]) }}</p>@endif
    <p class="invoice-note">{{ __('Le logement est loué par tranches de 24 heures. Merci de le libérer au plus tard à l\'heure indiquée. Merci de votre confiance.') }}</p>
</article>
