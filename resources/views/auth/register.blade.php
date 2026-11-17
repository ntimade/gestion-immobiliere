@extends('layouts.guest')
@section('title', __('Inscription'))
@section('card-class', 'wide')
@section('heading', __('Créer mon compte propriétaire'))
@section('subheading', __('Votre code organisation est généré à l\'inscription.'))

@section('content')
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <h2>{{ __('Votre activité') }}</h2>
        <x-field name="organisation_name" :label="__('Nom de votre activité ou votre nom')" :required="true" autofocus />
        <div class="form-grid">
            <x-field name="phone" :label="__('Téléphone')" type="tel" />
            <x-field name="address" :label="__('Adresse')" />
        </div>

        <h2>{{ __('Facturation') }}</h2>
        <div class="form-grid">
            <x-field name="phone_country" :label="__('Pays')" :required="true" :help="__('Sert à mettre les numéros de vos locataires au format international.')">
                    <select id="phone_country" name="phone_country" @required(true)>
                        <option value="">{{ __('Choisir…') }}</option>
                        @foreach (config('phone_countries') as $iso => [$label, $code])
                            <option value="{{ $iso }}" @selected(old('phone_country', null) === $iso)>{{ __($label) }} (+{{ $code }})</option>
                        @endforeach
                    </select>
                </x-field>
            <x-field name="currency" :label="__('Devise')" :required="true" >
                <select id="currency" name="currency" required>
                    <option value="">{{ __('Choisir…') }}</option>
                    @foreach ($currencies as $code => [$label])
                        <option value="{{ $code }}" @selected(old('currency') === $code)>{{ $code }} — {{ __($label) }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <h2>{{ __('Votre compte administrateur') }}</h2>
        <x-field name="name" :label="__('Nom complet')" :required="true" autocomplete="name" />
        <x-field name="email" :label="__('E-mail (servira de login)')" type="email" :required="true" autocomplete="username" />
        <div class="form-grid">
            <x-field name="password" :label="__('Mot de passe')" type="password" :required="true" minlength="8" autocomplete="new-password" />
            <x-field name="password_confirmation" :label="__('Confirmer le mot de passe')" type="password" :required="true" autocomplete="new-password" />
        </div>

        <h2>{{ __('Questions de sécurité') }}</h2>
        <p class="soft small">{{ __('Elles permettent de récupérer votre mot de passe en cas d\'oubli. Choisissez des réponses que vous seul connaissez.') }}</p>
        @include('auth.security-fields')

        <button type="submit" class="btn block">{{ __('Créer mon profil') }}</button>
    </form>
    <p class="auth-foot">{{ __('Déjà un compte ?') }} <a href="{{ route('login') }}">{{ __('Se connecter') }}</a></p>
@endsection
