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
            <h1>{{ __('État du bail') }}</h1>
            <span>{{ __('Du :v1 au :v2', ['v1' => period_label($from), 'v2' => period_label($to)]) }}</span>
            <span>{{ __('Imprimée le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
        </div>
    </header>

    <section class="invoice-parties">
        <div>
            <h2>{{ __('Locataire') }}</h2>
            <strong>{{ $lease->tenant->name }}</strong>
            @if ($lease->tenant->phone)<span>{{ __('Tél. : :v1', ['v1' => $lease->tenant->phone]) }}</span>@endif
            <span>{{ __('Entrée : :v1', ['v1' => $lease->start_date->format('d/m/Y')]) }}</span>
            <span>{{ $lease->end_date ? __('Sortie : :v1', ['v1' => $lease->end_date->format('d/m/Y')]) : __('Bail en cours au :v1', ['v1' => $lastDay->format('d/m/Y')]) }}</span>
        </div>
        <div>
            <h2>{{ __('Logement') }}</h2>
            <strong>{{ $lease->unit->label() }}</strong>
            <span>{{ __('Loyer : :v1', ['v1' => money($lease->rent)]) }}</span>
            <span>{{ __('Échéance : le :v1 de chaque mois', ['v1' => $lease->due_day]) }}</span>
            <span>{{ __('Facturation : :v1', ['v1' => mb_strtolower($lease->billingLabel())]) }}</span>
        </div>
    </section>

    @include('leases.statement-body')
</article>
