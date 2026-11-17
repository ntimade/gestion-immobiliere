<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $organisation->name }}</title>
    <link rel="icon" type="image/png" href="{{ asset_v('images/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/invoice.css') }}">
</head>
<body>
<div class="print-toolbar">
    <a class="btn ghost" href="{{ $backUrl }}">{{ __('Retour') }}</a>
    <x-lang-switch />
    <button type="button" class="btn" onclick="window.print()">{{ __('Imprimer') }}</button>
</div>

@foreach ($stays as $stay)
    @include('stays.invoice-sheet')
@endforeach
</body>
</html>
