@extends('layouts.app')
@section('title', __('Corriger le paiement'))

@section('content')
    <div class="page-head"><h1>{{ __('Corriger le paiement') }}</h1></div>
    <p class="soft">{{ $payment->lease->tenant->name }} · {{ $payment->lease->unit->label() }} · {{ __('Mois réglés : :v1', ['v1' => $payment->coveredLabel()]) }}</p>
    <div class="card narrow">
        <form method="POST" action="{{ route('payments.update', $payment) }}">
            @csrf @method('PUT')
            <div class="form-grid">
                <x-field name="amount" :label="__('Montant')" type="number" step="any" min="0" :required="true" :value="(float) $payment->amount" />
                <x-field name="paid_at" :label="__('Date')" type="date" :required="true" :value="$payment->paid_at->toDateString()" max="{{ now()->toDateString() }}" />
            </div>
            <div class="form-grid">
                <x-field name="method" :label="__('Mode de paiement')" :value="$payment->method" />
                <x-field name="reference" :label="__('Référence')" :value="$payment->reference" />
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                <a class="btn ghost" href="{{ route('leases.show', $payment->lease_id) }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection
