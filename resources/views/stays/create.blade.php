@extends('layouts.app')
@section('title', __('Nouveau séjour'))

@section('content')
    <div class="page-head"><h1>{{ __('Nouveau séjour') }}</h1></div>

    @if ($units->isEmpty())
        <div class="card">
            <p class="empty">{{ __('Aucun logement ne se loue encore à la nuit. Ouvrez un logement meublé, cliquez sur Modifier et renseignez son tarif journalier.') }}</p>
            <a class="btn ghost" href="{{ route('units.index') }}">{{ __('Voir les logements') }}</a>
        </div>
    @else
        <div class="card">
            <form method="POST" action="{{ route('stays.store') }}" id="stay-form">
                @csrf
                <h2>{{ __('Logement') }}</h2>
                <x-field name="unit_id" :label="__('Logement meublé')" :required="true">
                    <select id="unit_id" name="unit_id" required>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" data-rate="{{ (float) $unit->daily_rate }}" @selected((int) old('unit_id', $selectedUnit?->id) === $unit->id)>
                                {{ $unit->label() }} — {{ __(':v1 / 24 h', ['v1' => money($unit->daily_rate)]) }}@if ($unit->currentStay) — {{ __('occupé jusqu\'au :v1', ['v1' => $unit->currentStay->check_out_at->format('d/m/Y H:i')]) }}@endif
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <h2>{{ __('Client') }}</h2>
                <x-field name="guest_id" :label="__('Client déjà enregistré')" :help="__('Choisissez un client venu auparavant, ou laissez « Nouveau client » et remplissez sa fiche.')">
                    <select id="guest_id" name="guest_id">
                        <option value="">{{ __('Nouveau client') }}</option>
                        @foreach ($guests as $guest)
                            <option value="{{ $guest->id }}" @selected((int) old('guest_id') === $guest->id)>{{ $guest->name }}@if ($guest->phone) — {{ $guest->phone }}@endif</option>
                        @endforeach
                    </select>
                </x-field>
                <div id="new-guest">
                    <div class="form-grid">
                        <x-field name="name" :label="__('Nom du client')" :required="true" />
                        <x-field name="gender" :label="__('Civilité')">
                            <select id="gender" name="gender">
                                <option value="">—</option>
                                <option value="M" @selected(old('gender') === 'M')>{{ __('Monsieur') }}</option>
                                <option value="F" @selected(old('gender') === 'F')>{{ __('Madame') }}</option>
                            </select>
                        </x-field>
                    </div>
                    <div class="form-grid">
                        <x-field name="phone" :label="__('Téléphone (WhatsApp)')" type="tel" :help="__('Pour lui envoyer le remerciement et l\'heure de libération.')" />
                        <x-field name="id_number" :label="__('N° de pièce d\'identité')" />
                    </div>
                </div>

                <h2>{{ __('Séjour') }}</h2>
                <div class="form-grid">
                    <x-field name="check_in_at" :label="__('Arrivée (date et heure)')" type="datetime-local" :required="true" :value="now()->format('Y-m-d\TH:i')" />
                    <x-field name="days" :label="__('Nombre de jours (24 h chacun)')" type="number" min="1" max="{{ \App\Http\Controllers\StayController::MAX_DAYS }}" step="1" :required="true" :value="1"
                             :help="__('Une nuit minimum : 1 jour = 24 h, 2 jours = 48 h.')" />
                </div>
                <div class="form-grid">
                    <x-field name="daily_rate" :label="__('Tarif journalier (24 h)')" type="number" step="any" min="0" :required="true" :value="$selectedUnit ? (float) $selectedUnit->daily_rate : (float) $units->first()->daily_rate" />
                    <x-field name="paid_amount" :label="__('Montant payé')" type="number" step="any" min="0" :required="true" />
                </div>
                <div class="form-grid">
                    <x-field name="method" :label="__('Mode de paiement')" :placeholder="__('Espèces, Mobile Money…')" />
                    <x-field name="reference" :label="__('Référence')" />
                </div>
                <x-field name="notes" :label="__('Observation')" />

                <div class="subpanel" aria-live="polite">
                    <p><strong>{{ __('Montant du séjour') }} : <span id="stay-total">—</span></strong></p>
                    <p>{{ __('Libération du logement') }} : <strong id="stay-checkout">—</strong></p>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">{{ __('Enregistrer le séjour') }}</button>
                    <a class="btn ghost" href="{{ route('stays.index') }}">{{ __('Annuler') }}</a>
                </div>
            </form>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (function () {
            var form = document.getElementById('stay-form');
            if (!form) { return; }
            var unit = form.querySelector('#unit_id');
            var guest = form.querySelector('#guest_id');
            var newGuest = document.getElementById('new-guest');
            var name = form.querySelector('#name');
            var checkIn = form.querySelector('#check_in_at');
            var days = form.querySelector('#days');
            var rate = form.querySelector('#daily_rate');
            var paid = form.querySelector('#paid_amount');
            var paidTouched = paid.value !== '';
            var currency = {{ Js::from(auth()->user()->organisation->currency) }};
            var decimals = {{ (int) auth()->user()->organisation->currencyDecimals() }};

            function money(value) {
                return value.toLocaleString('fr-FR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + currency;
            }

            function pad(n) { return String(n).padStart(2, '0'); }

            function refresh() {
                var total = Math.max(1, parseInt(days.value, 10) || 0) * (parseFloat(rate.value) || 0);
                document.getElementById('stay-total').textContent = money(total);
                if (!paidTouched) { paid.value = Math.round(total * 1000) / 1000; }
                var start = checkIn.value ? new Date(checkIn.value) : null;
                var count = parseInt(days.value, 10);
                if (start && !isNaN(start.getTime()) && count >= 1) {
                    var end = new Date(start.getTime() + count * 24 * 3600 * 1000);
                    document.getElementById('stay-checkout').textContent = pad(end.getDate()) + '/' + pad(end.getMonth() + 1) + '/' + end.getFullYear() + ' ' + {{ Js::from(__('à')) }} + ' ' + pad(end.getHours()) + ':' + pad(end.getMinutes());
                } else {
                    document.getElementById('stay-checkout').textContent = '—';
                }
            }

            function refreshGuest() {
                var known = guest.value !== '';
                newGuest.hidden = known;
                name.required = !known;
            }

            unit.addEventListener('change', function () {
                rate.value = unit.selectedOptions[0].dataset.rate;
                refresh();
            });
            paid.addEventListener('input', function () { paidTouched = true; });
            [checkIn, days, rate].forEach(function (field) { field.addEventListener('input', refresh); });
            guest.addEventListener('change', refreshGuest);
            refreshGuest();
            refresh();
        })();
    </script>
@endpush
