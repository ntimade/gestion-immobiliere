@extends('layouts.guest')
@section('title', __('Contacter le support'))
@section('heading', __('Contacter le support'))
@section('subheading', __('Le support vérifiera votre identité, puis vous communiquera un mot de passe temporaire.'))

@section('content')
    <form method="POST" action="{{ route('support.request.store') }}">
        @csrf
        <x-field name="organisation_code" :label="__('Code organisation')" :value="$account['code'] ?? null" :required="true" :placeholder="__('Ex. EDN-001')" />
        <x-field name="email" :label="__('Login (e-mail)')" type="email" :value="$account['login'] ?? null" :required="true" autocomplete="username" />
        <x-field name="contact" :label="__('Où vous joindre')" :required="true" :help="__('Téléphone ou e-mail où le support peut vous contacter.')" />
        <x-field name="message" :label="__('Message')">
            <textarea id="message" name="message" rows="3" maxlength="1000">{{ old('message') }}</textarea>
        </x-field>
        <button type="submit" class="btn block">{{ __('Envoyer la demande') }}</button>
    </form>
    <p class="auth-foot"><a href="{{ route('login') }}">{{ __('Retour à la connexion') }}</a></p>
@endsection
