{{-- L'historique complet de la caution : le solde de la dernière ligne est ce qui reste détenu. --}}
@php($balance = 0)
@if ($lease->depositMovements->isEmpty())
    <p class="{{ $noteClass ?? 'soft small' }}">{{ __('Aucune caution enregistrée sur ce bail.') }}</p>
@else
    <table class="{{ $tableClass ?? '' }}">
        <thead>
            <tr>
                <th class="left">{{ __('Date') }}</th>
                <th class="left">{{ __('Opération') }}</th>
                <th class="left">{{ __('Détail') }}</th>
                @if ($showUser ?? false)<th class="left">{{ __('Saisi par') }}</th>@endif
                <th class="right">{{ __('Montant') }}</th>
                <th class="right">{{ __('Solde détenu') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lease->depositMovements as $movement)
                @php($balance = round($balance + (float) $movement->amount, 3))
                <tr>
                    <td class="left">{{ $movement->happened_on->format('d/m/Y') }}</td>
                    <td class="left">{{ $movement->label() }}</td>
                    <td class="left">{{ $movement->note ?: '—' }}</td>
                    @if ($showUser ?? false)<td class="left">{{ $movement->user?->name ?? '—' }}</td>@endif
                    <td class="right">{{ (float) $movement->amount > 0 ? '+ ' : ((float) $movement->amount < 0 ? '− ' : '') }}{{ money(abs((float) $movement->amount)) }}</td>
                    <td class="right">{{ money($balance) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
