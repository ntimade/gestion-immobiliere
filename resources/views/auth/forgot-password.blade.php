@extends('layouts.guest')
@section('title', __('Mot de passe oublié'))
@section('heading', __('Mot de passe oublié'))
@section('subheading', __('Indiquez votre compte, puis répondez à vos questions de sécurité.'))

@section('content')
    <form method="POST" action="{{ route('password.lookup') }}">
        @csrf
        <x-field name="organisation_code" :label="__('Code organisation')" :required="true" :placeholder="__('Ex. EDN-001')" autofocus style="text-transform: uppercase" />
        <x-field name="email" :label="__('Login (e-mail)')" type="email" :required="true" autocomplete="username" />
        <button type="submit" class="btn block">{{ __('Continuer') }}</button>
    </form>
    <p class="auth-foot">{{ __('Questions oubliées ?') }} <a href="{{ route('support.request') }}">{{ __('Contacter le support') }}</a></p>
    <p class="auth-foot"><a href="{{ route('login') }}">{{ __('Retour à la connexion') }}</a></p>
@endsection
