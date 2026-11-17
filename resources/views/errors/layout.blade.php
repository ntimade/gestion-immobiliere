<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ ui_theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('heading') — {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset_v('images/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/theme.css') }}">
</head>
<body>
<div class="lang-corner"><x-theme-switch />
    <x-lang-switch /></div>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-brand">
            <img class="auth-logo" src="{{ asset_v('images/logo.png') }}" alt="{{ config('app.name') }}">
            <h1>@yield('heading')</h1>
            <p>@yield('message')</p>
        </div>
        <a class="btn block" href="{{ url('/') }}">{{ __('Retour à l\'accueil') }}</a>
    </div>
    <x-app-footer />
</div>
</body>
</html>
