@extends('layouts.guest')
@section('title', __('Bienvenue'))
@section('heading', __('Votre profil est créé'))
@section('subheading', __('Votre fichier d\'identifiants se télécharge automatiquement.'))

@section('content')
    <div class="credentials">
        <div><span class="soft">{{ __('Code organisation') }}</span><strong>{{ $user->organisation->code }}</strong></div>
        <div><span class="soft">{{ __('Login') }}</span><strong>{{ $user->email }}</strong></div>
        <div><span class="soft">{{ __('Mot de passe') }}</span><strong>{{ __('dans le fichier') }}</strong></div>
    </div>
    <div class="flash info small">{{ __('Conservez ce fichier en lieu sûr : il ne pourra plus être téléchargé après cette étape.') }}</div>
    <p><a class="btn ghost block" href="{{ route('welcome.download') }}">{{ __('Télécharger à nouveau le fichier') }}</a></p>
    <form method="POST" action="{{ route('welcome.finish') }}">
        @csrf
        <button type="submit" class="btn block">{{ __('J\'ai sauvegardé mon fichier, continuer') }}</button>
    </form>
    <iframe src="{{ route('welcome.download') }}" title="{{ __('Téléchargement du fichier d\'identifiants') }}" hidden></iframe>
@endsection
