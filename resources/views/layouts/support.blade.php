<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ ui_theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ __('Support') }} — {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset_v('images/favicon.png') }}">
    @include('partials.installable-head')
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/theme.css') }}">
</head>
<body>
<header class="support-bar">
    <a class="support-brand" href="{{ route('support.requests.index') }}">
        <img src="{{ asset_v('images/logo-icon.png') }}" alt="" width="30" height="30">
        <span>{{ __('Poste de support') }}</span>
    </a>
    <nav class="support-nav" aria-label="{{ __('Menu principal') }}">
        <a href="{{ route('support.requests.index') }}" @class(['active' => request()->routeIs('support.requests.*')])>{{ __('Demandes') }}</a>
        <a href="{{ route('support.accounts.index') }}" @class(['active' => request()->routeIs('support.accounts.*')])>{{ __('Comptes') }}</a>
        <a href="{{ route('support.subscriptions.index') }}" @class(['active' => request()->routeIs('support.subscriptions.*')])>{{ __('Abonnements') }}</a>
    </nav>
    <x-theme-switch />
    <x-lang-switch />
    <form method="POST" action="{{ route('support.logout') }}">
        @csrf
        <button type="submit" class="icon-btn" aria-label="{{ __('Déconnexion') }}" title="{{ __('Déconnexion') }}"><x-icon name="log-out" /></button>
    </form>
</header>
<main class="content support-content">
    <div class="content-inner">
        @if (session('success'))<div class="flash success">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash error">{{ session('error') }}</div>@endif
        @yield('content')
        <x-app-footer />
    </div>
</main>
@include('layouts.table-text')
<script src="{{ asset_v('js/app.js') }}" data-csrf-url="{{ route('csrf.refresh') }}"></script>
</body>
</html>
