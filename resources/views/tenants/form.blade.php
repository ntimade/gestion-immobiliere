@extends('layouts.app')
@section('title', $tenant->exists ? __('Modifier le locataire') : __('Nouveau locataire'))

@section('content')
    <div class="page-head"><h1>{{ $tenant->exists ? __('Modifier le locataire') : __('Nouveau locataire') }}</h1></div>
    <div class="card narrow">
        <form method="POST" action="{{ $tenant->exists ? route('tenants.update', $tenant) : route('tenants.store') }}">
            @csrf
            @if ($tenant->exists) @method('PUT') @endif
            <x-field name="name" :label="__('Nom')" :value="$tenant->name" :required="true" autofocus />
            <x-field name="gender" :label="__('Sexe')" :help="__('Sert à écrire Monsieur ou Madame dans les messages.')">
                <select id="gender" name="gender">
                    <option value="">{{ __('Non renseigné (Monsieur/Madame)') }}</option>
                    <option value="M" @selected(old('gender', $tenant->gender) === 'M')>{{ __('Homme (Monsieur)') }}</option>
                    <option value="F" @selected(old('gender', $tenant->gender) === 'F')>{{ __('Femme (Madame)') }}</option>
                </select>
            </x-field>
            <div class="form-grid">
                <x-field name="phone" :label="__('Téléphone')" type="tel" :value="$tenant->phone" :help="__('L\'indicatif du pays est ajouté automatiquement.')" />
                <x-field name="email" :label="__('E-mail')" type="email" :value="$tenant->email" />
            </div>
            <x-field name="id_number" :label="__('N° de pièce d\'identité')" :value="$tenant->id_number" />
            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                <a class="btn ghost" href="{{ $tenant->exists ? route('tenants.show', $tenant) : route('tenants.index') }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection
