<article class="invoice statement">
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
            <h1>{{ __('Historique du locataire') }}</h1>
            <span>{{ __('Imprimée le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
        </div>
    </header>

    <section class="invoice-parties">
        <div>
            <h2>{{ __('Locataire') }}</h2>
            <strong>{{ $tenant->name }}</strong>
            @if ($tenant->phone)<span>{{ __('Tél. : :v1', ['v1' => $tenant->phone]) }}</span>@endif
            @if ($tenant->id_number)<span>{{ __('N° de pièce d\'identité') }} : {{ $tenant->id_number }}</span>@endif
        </div>
        <div>
            <h2>{{ __('Baux') }}</h2>
            <strong>{{ $entries->count() }}</strong>
        </div>
    </section>

    @if ($entries->isEmpty())
        <p class="invoice-note">{{ __('Aucun bail enregistré pour ce locataire.') }}</p>
    @else
        <table class="invoice-totals">
            <tr><th>{{ __('Total facturé') }}</th><td>{{ money($totals['billed']) }}</td></tr>
            <tr class="subtotal"><th>{{ __('Total versé') }}</th><td>{{ money($totals['paid']) }}</td></tr>
            <tr class="grand-total"><th>{{ __('Total des impayés') }}</th><td>{{ money($totals['unpaid']) }}</td></tr>
            <tr class="subtotal"><th>{{ __('Caution versée') }}</th><td>{{ money($totals['deposit']) }}</td></tr>
            <tr><th>{{ __('Caution retenue') }}</th><td>{{ money($totals['retained']) }}</td></tr>
            <tr><th>{{ __('Caution rendue') }}</th><td>{{ money($totals['refunded']) }}</td></tr>
            <tr class="subtotal"><th>{{ __('Caution encore détenue') }}</th><td>{{ money($totals['held']) }}</td></tr>
        </table>

        @foreach ($entries as $entry)
            <h2 class="history-lease">
                {{ $entry['lease']->unit->label() }}
                <small>{{ __('Entrée : :v1', ['v1' => $entry['lease']->start_date->format('d/m/Y')]) }} — {{ $entry['lease']->end_date ? __('Sortie : :v1', ['v1' => $entry['lease']->end_date->format('d/m/Y')]) : __('Bail en cours au :v1', ['v1' => $entry['lastDay']->format('d/m/Y')]) }} — {{ __('Loyer : :v1', ['v1' => money($entry['lease']->rent)]) }}</small>
            </h2>
            @include('leases.statement-body', $entry)
        @endforeach
    @endif
</article>
