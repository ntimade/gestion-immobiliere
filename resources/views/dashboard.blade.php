@extends('layouts.app')
@section('title', __('Tableau de bord'))

@section('content')
    <div class="page-head"><h1>{{ __('Tableau de bord') }}</h1></div>

    @if ($properties === 0)
        <div class="card">
            <h2>{{ __('Pour commencer') }}</h2>
            <p class="soft">{{ __('Ajoutez votre premier bien, ses appartements ou ses chambres avec leur montant, puis enregistrez l\'entrée de chaque locataire et sa caution.') }}</p>
            <a class="btn" href="{{ route('properties.create') }}">{{ __('Ajouter un bien') }}</a>
        </div>
    @endif

    @if ($remindersWaiting > 0)
        <div class="flash info">{{ __(':v1 rappel(s) de loyer à envoyer aujourd\'hui.', ['v1' => $remindersWaiting]) }} <a href="{{ route('messages.index') }}">{{ __('Voir la liste') }}</a></div>
    @endif

    <div class="stats">
        <div class="stat"><span>{{ __('Logements occupés') }}</span><strong>{{ $occupied }} / {{ $units }}</strong></div>
        <div class="stat"><span>{{ __('Logements libres') }}</span><strong>{{ $vacant }}</strong></div>
        <div class="stat"><span>{{ __('Revenu mensuel actuel') }}</span><strong>{{ money($income['current']) }}</strong></div>
        <div class="stat"><span>{{ __('Revenu estimé si tout est loué') }}</span><strong>{{ money($income['estimated']) }}</strong></div>
        <div class="stat"><span>{{ __('Loyers :v1', ['v1' => of_period($period)]) }}</span><strong>{{ money($billed) }}</strong></div>
        <div class="stat"><span>{{ __('Encaissé ce mois-ci') }}</span><strong>{{ money($collected) }}</strong></div>
        <div @class(['stat', 'alert' => $overdueTotal > 0])><span>{{ __('Loyers en retard') }}</span><strong>{{ money($overdueTotal) }}</strong></div>
        <div class="stat"><span>{{ __('Cautions détenues') }}</span><strong>{{ money($depositsHeld) }}</strong></div>
    </div>

    @if ($notices->isNotEmpty())
        <div class="card">
            <h2>{{ __('Préavis en cours') }}</h2>
            <div class="table-wrap cards-wide"><table>
                <tr><th>{{ __('Locataire') }}</th><th>{{ __('Logement') }}</th><th>{{ __('Départ') }}</th><th>{{ __('État des lieux') }}</th><th></th></tr>
                @foreach ($notices as $lease)
                    <tr>
                        <td><a href="{{ route('leases.show', $lease) }}#preavis">{{ $lease->tenant->name }}</a></td>
                        <td>{{ $lease->unit->label() }}</td>
                        <td @class(['danger-text' => $lease->exitToFinalize()])>{{ $lease->notice_end_date->format('d/m/Y') }}</td>
                        <td>{{ $lease->inspection_at?->format('d/m/Y H:i') ?? __('À planifier') }}</td>
                        <td>
                            <div class="actions">
                                @if ($lease->exitToFinalize())
                                    <a class="btn small danger" href="{{ route('leases.exit', $lease) }}">{{ __('Finaliser la sortie') }}</a>
                                @else
                                    <a class="btn ghost small" href="{{ route('leases.show', $lease) }}#preavis">{{ __('Ouvrir') }}</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Échéances des 7 prochains jours') }}</h2>
        @if ($upcoming->isEmpty())
            <p class="empty">{{ __('Aucun loyer à échéance dans les 7 prochains jours.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table>
                <tr><th>{{ __('Échéance') }}</th><th>{{ __('Locataire') }}</th><th>{{ __('Logement') }}</th><th class="right">{{ __('Reste à payer') }}</th></tr>
                @foreach ($upcoming as $invoice)
                    <tr>
                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                        <td><a href="{{ route('leases.show', $invoice->lease_id) }}">{{ $invoice->lease->tenant->name }}</a></td>
                        <td>{{ $invoice->lease->unit->label() }}</td>
                        <td class="right">{{ money($invoice->balance()) }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Loyers en retard') }}</h2>
        @if ($late->isEmpty())
            <p class="empty">{{ __('Aucun loyer en retard.') }}</p>
        @else
            <div class="table-wrap cards-wide"><table>
                <tr><th>{{ __('Échéance') }}</th><th>{{ __('Locataire') }}</th><th>{{ __('Logement') }}</th><th>{{ __('Mois') }}</th><th class="right">{{ __('Reste à payer') }}</th></tr>
                @foreach ($late as $invoice)
                    <tr>
                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                        <td><a href="{{ route('leases.show', $invoice->lease_id) }}">{{ $invoice->lease->tenant->name }}</a></td>
                        <td>{{ $invoice->lease->unit->label() }}</td>
                        <td>{{ $invoice->label() }}</td>
                        <td class="right danger-text">{{ money($invoice->balance()) }}</td>
                    </tr>
                @endforeach
            </table></div>
        @endif
    </div>
@endsection
