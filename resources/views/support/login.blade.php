@extends('layouts.guest')
@section('backdrop')
    @include('partials.brick-wall')
@endsection
@section('title', __('Poste de support'))
@section('heading', __('Poste de support'))
@section('subheading', __('Réservé à l\'équipe de support.'))

@section('content')
    <form method="POST" action="{{ route('support.login') }}">
        @csrf
        <x-field name="email" :label="__('E-mail')" type="email" :required="true" autofocus autocomplete="username" />
        <x-field name="password" :label="__('Mot de passe')" type="password" :required="true" autocomplete="current-password" />
        <button type="submit" class="btn block">{{ __('Se connecter') }}</button>
    </form>
@endsection
