@extends('layouts.app')
@section('title', __('Corriger la facture'))

@section('content')
    <div class="page-head"><h1>{{ __('Corriger la facture') }}</h1></div>
    <p class="soft">{{ $invoice->lease->tenant->name }} · {{ $invoice->lease->unit->label() }} · {{ $invoice->label() }}</p>
    <div class="card narrow">
        <form method="POST" action="{{ route('invoices.update', $invoice) }}">
            @csrf @method('PUT')
            <div class="form-grid">
                <x-field name="amount" :label="__('Montant')" type="number" step="any" min="0" :required="true" :value="(float) $invoice->amount"
                         :help="__('Si ce qui est déjà payé dépasse le nouveau montant, le surplus passe au mois suivant.')" />
                <x-field name="due_date" :label="__('Échéance')" type="date" :required="true" :value="$invoice->due_date->toDateString()" />
            </div>
            <x-field name="notes" :label="__('Observation')" :value="$invoice->notes" />
            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                <a class="btn ghost" href="{{ route('leases.show', $invoice->lease_id) }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection
