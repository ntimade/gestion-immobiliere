@extends('layouts.guest')
@section('title', __('Nouveau mot de passe'))
@section('heading', __('Nouveau mot de passe'))
@section('subheading', __('Choisissez votre nouveau mot de passe.'))

@section('content')
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <x-field name="password" :label="__('Nouveau mot de passe')" type="password" :required="true" minlength="8" autofocus autocomplete="new-password" />
        <x-field name="password_confirmation" :label="__('Confirmer le mot de passe')" type="password" :required="true" autocomplete="new-password" />
        <button type="submit" class="btn block">{{ __('Enregistrer') }}</button>
    </form>
@endsection
