@extends('layouts.app')
@section('title', $member->exists ? __('Modifier l\'utilisateur') : __('Nouvel utilisateur'))

@section('content')
    @php($isSelf = $member->exists && $member->is(auth()->user()))
    <div class="page-head"><h1>{{ $member->exists ? __('Modifier l\'utilisateur') : __('Nouvel utilisateur') }}</h1></div>
    <div class="card narrow">
        <form method="POST" action="{{ $member->exists ? route('users.update', $member->id) : route('users.store') }}" autocomplete="off">
            @csrf
            @if ($member->exists) @method('PUT') @endif
            <x-field name="name" :label="__('Nom')" :value="$member->name" :required="true" autofocus />
            <x-field name="email" :label="__('E-mail (servira de login)')" type="email" :value="$member->email" :required="true" autocomplete="off" />

            @if ($isSelf)
                <p class="soft small">{{ __('Votre rôle ne se change pas ici, et votre mot de passe se change dans les paramètres.') }}</p>
            @else
                <x-field name="role" :label="__('Rôle')" :required="true">
                    <div class="choices">
                        <label class="choice"><input type="radio" name="role" value="manager" required @checked(old('role', $member->exists ? $member->role : 'manager') === 'manager')> <span><strong>{{ __('Gestionnaire') }}</strong><br><span class="soft small">{{ __('Biens, locataires, factures, paiements et messages.') }}</span></span></label>
                        <label class="choice"><input type="radio" name="role" value="admin" required @checked(old('role', $member->exists ? $member->role : 'manager') === 'admin')> <span><strong>{{ __('Administrateur') }}</strong><br><span class="soft small">{{ __('Tout, plus les utilisateurs, la société et l\'abonnement.') }}</span></span></label>
                    </div>
                </x-field>
                <div class="form-grid">
                    <x-field name="password" :label="$member->exists ? __('Nouveau mot de passe provisoire') : __('Mot de passe provisoire')" type="password" :required="! $member->exists" minlength="8" autocomplete="new-password"
                             :help="$member->exists ? __('Laissez vide pour ne pas le changer. Sinon, l\'utilisateur est déconnecté.') : __('Il devra le changer à sa première connexion.')" />
                    <x-field name="password_confirmation" :label="__('Confirmer')" type="password" :required="! $member->exists" autocomplete="new-password" />
                </div>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                <a class="btn ghost" href="{{ route('users.index') }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection
