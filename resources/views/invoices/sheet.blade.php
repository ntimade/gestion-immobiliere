@php
    $others = $otherMonths[$invoice->id] ?? collect();
    $lease = $invoice->lease;
@endphp
<article class="invoice">
    <header class="invoice-head">
        <div class="invoice-org">
            @if ($organisation->logo_path)
                <img class="invoice-logo" src="{{ $logoSrc }}" alt="{{ __('Logo de :v1', ['v1' => $organisation->name]) }}">
            @endif
            <strong>{{ $organisation->name }}</strong>
            @if ($organisation->address)<span>{{ $organisation->address }}</span>@endif
            @if ($organisation->phone)<span>{{ __('Tél. : :v1', ['v1' => $organisation->phone]) }}</span>@endif
        </div>
        <div class="invoice-title">
            <h1>{{ __('Facture de loyer') }}</h1>
            <span class="invoice-number">{{ $invoice->number() }}</span>
            <span>{{ __('Période : :v1', ['v1' => $invoice->label()]) }}</span>
            <span>{{ __('Imprimée le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
            <strong class="invoice-due">{{ __('À payer avant le :v1', ['v1' => $invoice->due_date->format('d/m/Y')]) }}</strong>
        </div>
    </header>

    <section class="invoice-parties">
        <div>
            <h2>{{ __('Locataire') }}</h2>
            <strong>{{ $lease->tenant->name }}</strong>
            @if ($lease->tenant->phone)<span>{{ __('Tél. : :v1', ['v1' => $lease->tenant->phone]) }}</span>@endif
        </div>
        <div>
            <h2>{{ __('Logement') }}</h2>
            <strong>{{ $lease->unit->label() }}</strong>
            <span>{{ $lease->unit->typeLabel() }}</span>
            @if ($lease->unit->property->address)<span>{{ $lease->unit->property->address }}</span>@endif
        </div>
    </section>

    <table class="invoice-totals">
        <tr class="subtotal"><th>{{ __('Loyer de :v1', ['v1' => $invoice->label()]) }}</th><td>{{ money($invoice->amount) }}</td></tr>
        @if ((float) $invoice->paid_amount > 0)
            <tr><th>{{ __('Déjà payé') }}</th><td>− {{ money($invoice->paid_amount) }}</td></tr>
        @endif
        @foreach ($others as $month)
            <tr><th>{{ __('Reste dû sur :v1', ['v1' => $month->label()]) }}</th><td>{{ money($month->balance()) }}</td></tr>
        @endforeach
        <tr class="grand-total"><th>{{ __('Total à payer') }}</th><td>{{ money($invoice->balance() + $others->sum(fn ($month) => $month->balance())) }}</td></tr>
    </table>

    <p class="invoice-note">{{ $lease->depositSummary() }}. {{ __('La caution ne compte pas dans le total à payer.') }}</p>
    @if ($invoice->notes)<p class="invoice-note">{{ __('Observation : :v1', ['v1' => $invoice->notes]) }}</p>@endif
</article>
