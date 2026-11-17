@extends('layouts.app')
@section('title', $unit->exists ? __('Modifier le logement') : __('Nouveau logement'))

@section('content')
    <div class="page-head"><h1>{{ $unit->exists ? __('Modifier le logement') : __('Nouveau logement') }}</h1></div>
    <div class="card narrow">
        @if ($properties->isEmpty())
            <p class="empty">{{ __('Ajoutez d\'abord un bien.') }}</p>
            <a class="btn" href="{{ route('properties.create') }}">{{ __('Ajouter un bien') }}</a>
        @else
            <form method="POST" action="{{ $unit->exists ? route('units.update', $unit) : route('units.store') }}" data-rent-scope>
                @csrf
                @if ($unit->exists) @method('PUT') @endif
                <x-field name="property_id" :label="__('Bien')" :required="true">
                    <select id="property_id" name="property_id" required>
                        <option value="">{{ __('Choisir…') }}</option>
                        @foreach ($properties as $property)
                            <option value="{{ $property->id }}" @selected(old('property_id', $unit->property_id) == $property->id)>{{ $property->name }}</option>
                        @endforeach
                    </select>
                </x-field>
                <div class="form-grid">
                    <x-field name="number" :label="__('Numéro')" :value="$unit->number" :required="true" :placeholder="__('Ex. 12 ou A3')" />
                    <x-field name="type" :label="__('Type')" :required="true">
                        <select id="type" name="type" required>
                            @foreach (\App\Models\Unit::TYPES as $type)
                                <option value="{{ $type }}" @selected(old('type', $unit->type) === $type)>{{ \App\Models\Unit::typeName($type) }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
                <x-field name="rent" data-rent :label="__('Montant du loyer par mois')" type="number" step="any" min="0" :required="true"
                         :value="$unit->rent === null ? null : (float) $unit->rent" :help="__('Repris à chaque nouvelle entrée. Il peut être ajusté pour chaque locataire.')" />

                <h2>{{ __('Détails du logement') }}</h2>
                @include('units.detail-fields', ['unit' => $unit])

                <div class="form-actions">
                    <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                    <a class="btn ghost" href="{{ $unit->exists ? route('units.show', $unit) : route('units.index') }}">{{ __('Annuler') }}</a>
                </div>
            </form>
        @endif
    </div>
@endsection
