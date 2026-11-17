@extends('layouts.app')
@section('title', __('Sortie du locataire'))

@section('content')
    <div class="page-head"><h1>{{ __('Sortie du locataire') }}</h1></div>
    <p class="soft">{{ $lease->tenant->name }} · {{ $lease->unit->label() }}</p>

    @if ($unpaid > 0)
        <div class="flash error">{{ __('Ce locataire doit encore :v1. Cette dette reste enregistrée après sa sortie, même si vous retenez une partie de la caution.', ['v1' => money($unpaid)]) }}</div>
    @endif

    <div class="card narrow">
        <form method="POST" action="{{ route('leases.end', $lease) }}">
            @csrf
            @php($inspectionCost = (float) $lease->inspection_cost)
            @if ($lease->onNotice())
                <p class="flash info">
                    {{ __('Préavis reçu le :v1, départ prévu le :v2.', ['v1' => $lease->notice_given_on->format('d/m/Y'), 'v2' => $lease->notice_end_date->format('d/m/Y')]) }}
                    @if ($lease->inspection_report || $inspectionCost > 0)
                        {{ __('Le remboursement de la caution est prérempli d\'après l\'état des lieux.') }}
                    @endif
                </p>
            @endif
            <x-field name="end_date" :label="__('Date de sortie')" type="date" :value="($lease->notice_end_date ?? now())->toDateString()" :required="true"
                     :help="__('Le logement redevient libre le lendemain. Le mois de sortie reste facturé en entier : corrigez sa facture si besoin. Les mois déjà payés après la sortie sont remboursés au locataire et notés dans son historique.')" />

            <h2>{{ __('La caution') }}</h2>
            @if ((float) $lease->deposit_amount > 0)
                <p class="soft small">
                    {{ __('Caution versée : :v1', ['v1' => money($lease->deposit_amount)]) }}
                    @if ($lease->deposit_paid_at) · {{ __('le :v1', ['v1' => $lease->deposit_paid_at->format('d/m/Y')]) }}@endif
                </p>

                @php($mode = old('deposit_mode', $inspectionCost > 0 ? 'partial' : 'complete'))
                <div class="field">
                    <div class="choices">
                        <label class="choice"><input type="radio" name="deposit_mode" value="complete" @checked($mode === 'complete')> {{ __('Remboursement complet') }}</label>
                        <label class="choice"><input type="radio" name="deposit_mode" value="partial" @checked($mode === 'partial')> {{ __('Remboursement partiel') }}</label>
                    </div>
                </div>

                <div id="deposit-partial">
                    <x-field name="deposit_refunded" :label="__('Montant remboursé')" type="number" step="any" min="0" max="{{ (float) $lease->deposit_amount }}"
                             :value="$inspectionCost > 0 ? max((float) $lease->deposit_amount - $inspectionCost, 0) : null"
                             :help="__('Mettez 0 si rien n\'est remboursé.')" />
                    <x-field name="deposit_note" :label="__('Justification de la retenue')" :placeholder="__('Réparations, peinture, loyers impayés…')"
                             :value="$lease->inspection_report ? \Illuminate\Support\Str::limit(__('État des lieux du :v1 : :v2', ['v1' => ($lease->inspection_at ?? now())->format('d/m/Y'), 'v2' => $lease->inspection_report]), 250) : null"
                             :help="__('Obligatoire. Elle reste dans l\'historique de la caution et figure dans le décompte envoyé au locataire.')" />
                    <p class="soft small" id="deposit-retained-line"></p>
                    @if ($unpaid > 0)
                        <x-field name="deposit_to_rent" :label="__('Part de la retenue affectée aux loyers impayés')" type="number" step="any" min="0"
                                 max="{{ min((float) $lease->deposit_amount, $unpaid) }}" :value="0"
                                 :help="__('Cette somme règle les loyers impayés, du plus ancien au plus récent, et la dette baisse d\'autant. Mettez 0 pour garder la retenue à part.')" />
                    @endif
                </div>

                <div id="deposit-date">
                    <x-field name="deposit_refunded_on" :label="__('Date du remboursement')" type="date" :value="now()->toDateString()" />
                </div>
            @else
                <p class="soft small">{{ __('Aucune caution enregistrée sur ce bail : rien à rembourser.') }}</p>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn danger">{{ __('Enregistrer la sortie') }}</button>
                <a class="btn ghost" href="{{ route('leases.show', $lease) }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection

@if ((float) $lease->deposit_amount > 0)
    @push('scripts')
        <script>
            (function () {
                var total = @json((float) $lease->deposit_amount);
                var currency = @json(auth()->user()->organisation->currency);
                var retainedText = @json(__('Retenue sur la caution : :v1'));
                var partial = document.getElementById('deposit-partial');
                var dateBlock = document.getElementById('deposit-date');
                var refunded = document.getElementById('deposit_refunded');
                var note = document.getElementById('deposit_note');
                var line = document.getElementById('deposit-retained-line');
                var endDate = document.getElementById('end_date');
                var refundDate = document.getElementById('deposit_refunded_on');
                var dateTouched = false;

                // Masqués, ces champs ne bloquent pas l'envoi d'un remboursement complet.
                function refresh() {
                    var isPartial = document.querySelector('input[name="deposit_mode"]:checked').value === 'partial';
                    partial.hidden = !isPartial;
                    refunded.disabled = note.disabled = !isPartial;
                    var toRent = document.getElementById('deposit_to_rent');
                    if (toRent) { toRent.disabled = !isPartial; }
                    refunded.required = note.required = isPartial;

                    var amount = parseFloat(refunded.value) || 0;
                    line.textContent = retainedText.replace(':v1', Math.max(total - amount, 0).toLocaleString(document.documentElement.lang || 'fr') + ' ' + currency);
                    // Rien à dater si rien n'est remboursé.
                    dateBlock.hidden = isPartial && amount === 0;
                }

                document.querySelectorAll('input[name="deposit_mode"]').forEach(function (radio) { radio.addEventListener('change', refresh); });
                refunded.addEventListener('input', refresh);
                refundDate.addEventListener('input', function () { dateTouched = true; });
                endDate.addEventListener('change', function () { if (!dateTouched) { refundDate.value = endDate.value; } });
                refresh();
            })();
        </script>
    @endpush
@endif
