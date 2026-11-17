<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Historique du locataire') }} — {{ $tenant->name }}</title>
    <link rel="icon" type="image/png" href="{{ asset_v('images/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/invoice.css') }}">
</head>
<body>
<div class="print-toolbar">
    <a class="btn ghost" href="{{ route('history.index') }}">{{ __('Retour') }}</a>
    <x-lang-switch />
    <a class="btn ghost" href="{{ route('tenants.history.pdf', $tenant) }}">{{ __('PDF') }}</a>
    <button type="button" class="btn" onclick="window.print()">{{ __('Imprimer') }}</button>
</div>

@include('tenants.history-sheet')
</body>
</html>
