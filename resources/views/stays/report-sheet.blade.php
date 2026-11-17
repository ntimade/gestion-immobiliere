{{-- L'historique des séjours selon les filtres choisis (client, logement, période). --}}
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
            <h1>{{ __('Historique des séjours') }}</h1>
            <span>{{ __('Imprimée le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
        </div>
    </header>

    <section class="invoice-parties">
        <div>
            <h2>{{ __('Client') }}</h2>
            <strong>{{ $guest?->name ?? __('Tous les clients') }}</strong>
            @if ($guest?->phone)<span>{{ __('Tél. : :v1', ['v1' => $guest->phone]) }}</span>@endif
            @if ($unit)<span>{{ __('Logement : :v1', ['v1' => $unit->label()]) }}</span>@endif
        </div>
        <div>
            <h2>{{ __('Période') }}</h2>
            <strong>
                @if ($filters['from'] && $filters['to'])
                    {{ __('Du :v1 au :v2', ['v1' => \Illuminate\Support\Carbon::parse($filters['from'])->format('d/m/Y'), 'v2' => \Illuminate\Support\Carbon::parse($filters['to'])->format('d/m/Y')]) }}
                @elseif ($filters['from'])
                    {{ __('Depuis le :v1', ['v1' => \Illuminate\Support\Carbon::parse($filters['from'])->format('d/m/Y')]) }}
                @elseif ($filters['to'])
                    {{ __('Jusqu\'au :v1', ['v1' => \Illuminate\Support\Carbon::parse($filters['to'])->format('d/m/Y')]) }}
                @else
                    {{ __('Toutes les dates') }}
                @endif
            </strong>
            <span>{{ __(':v1 séjour(s), :v2 jour(s)', ['v1' => $totals['count'], 'v2' => $totals['days']]) }}</span>
        </div>
    </section>

    @if ($stays->isEmpty())
        <p class="invoice-note">{{ __('Aucun séjour.') }}</p>
    @else
        <table class="stay-list">
            <thead>
                <tr><th>{{ __('Facture') }}</th><th>{{ __('Client') }}</th><th>{{ __('Logement') }}</th><th>{{ __('Arrivée') }}</th><th>{{ __('Libération') }}</th><th>{{ __('Durée') }}</th><th class="right">{{ __('Montant') }}</th><th class="right">{{ __('Payé') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($stays as $stay)
                    <tr>
                        <td>{{ $stay->number() }}</td>
                        <td>{{ $stay->guest->name }}</td>
                        <td>{{ $stay->unit->label() }}</td>
                        <td>{{ $stay->check_in_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $stay->check_out_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $stay->daysLabel() }}</td>
                        <td class="right">{{ money($stay->amount) }}</td>
                        <td class="right">{{ money($stay->paid_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="6">{{ __('Total') }}</td><td class="right">{{ money($totals['amount']) }}</td><td class="right">{{ money($totals['paid']) }}</td></tr>
            </tfoot>
        </table>
        <table class="invoice-totals">
            <tr class="subtotal"><th>{{ __('Montant des séjours') }}</th><td>{{ money($totals['amount']) }}</td></tr>
            <tr><th>{{ __('Payé') }}</th><td>{{ money($totals['paid']) }}</td></tr>
            <tr class="grand-total"><th>{{ __('Reste à payer') }}</th><td>{{ money($totals['balance']) }}</td></tr>
        </table>
    @endif
</article>
