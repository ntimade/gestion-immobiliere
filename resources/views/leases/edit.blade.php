@extends('layouts.app')
@section('title', __('Modifier le bail'))

@section('content')
    <div class="page-head"><h1>{{ __('Modifier le bail') }}</h1></div>
    <p class="soft">{{ $lease->tenant->name }} · {{ $lease->unit->label() }}</p>
    <div class="card narrow">
        <form method="POST" action="{{ route('leases.update', $lease) }}">
            @csrf @method('PUT')
            <x-field name="start_date" :label="__('Date d\'entrée')" type="date" :required="true" :value="$lease->start_date->toDateString()"
                     :help="__('Se corrige pour régulariser un client. Les factures existantes ne changent pas.')" />
            <div class="form-grid">
                <x-field name="rent" :label="__('Loyer par mois')" type="number" step="any" min="0" :required="true" :value="(float) $lease->rent"
                         :help="__('S\'applique aux prochaines factures. Les factures existantes se corrigent une par une.')" />
                <x-field name="due_day" :label="__('Jour d\'échéance chaque mois')" type="number" min="1" max="31" step="1" :required="true" :value="$lease->due_day"
                         :help="__('S\'applique aux prochaines factures.')" />
            </div>
            <x-field name="billing_months" :label="__('Cycle de facturation (en mois)')" type="number" min="1" max="24" step="1" :required="true" :value="$lease->billing_months"
                     :help="__('1 : chaque mois, 3 : chaque trimestre… S\'applique aux prochaines factures, les factures existantes ne changent pas.')" />
            <div class="form-grid">
                <x-field name="deposit_months" :label="__('Caution en mois de loyer')" type="number" min="0" max="24" step="1" :value="$lease->deposit_months"
                         :help="__('Négociable pour chaque client.')" />
                <x-field name="deposit_amount" :label="__('Montant de la caution')" type="number" step="any" min="0" :required="true" :value="(float) $lease->deposit_amount"
                         :help="__('Un multiple du loyer. Mettez 0 pour retirer la caution de ce bail. La correction reste dans l\'historique.')" />
            </div>
            <x-field name="deposit_paid_at" :label="__('Caution versée le')" type="date" :value="$lease->deposit_paid_at?->toDateString()" />
            <x-field name="notes" :label="__('Observation')" :value="$lease->notes" />
            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                <a class="btn ghost" href="{{ route('leases.show', $lease) }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection
