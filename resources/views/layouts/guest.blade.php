<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ ui_theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset_v('images/favicon.png') }}">
    @include('partials.installable-head')
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/theme.css') }}">
</head>
<body>
<div class="lang-corner"><x-theme-switch />
    <x-lang-switch /></div>
@yield('backdrop')
<div class="auth-page">
    <div class="auth-card @yield('card-class')">
        <div class="auth-brand">
            <img class="auth-logo" src="{{ asset_v('images/logo.png') }}" alt="{{ config('app.name') }}">
            <h1>@yield('heading')</h1>
            @hasSection('subheading')<p>@yield('subheading')</p>@endif
        </div>
        @if (session('status'))<div class="flash success">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="flash error">{{ session('error') }}</div>@endif
        @yield('content')
        <x-install-app class="auth-install" />
    </div>
    <x-app-footer />
</div>
<script>
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = button.parentElement.querySelector('input');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? {{ Js::from(__('Masquer')) }} : {{ Js::from(__('Afficher')) }};
        });
    });
</script>
<script src="{{ asset_v('js/app.js') }}" data-csrf-url="{{ route('csrf.refresh') }}"></script>
@stack('scripts')
</body>
</html>
