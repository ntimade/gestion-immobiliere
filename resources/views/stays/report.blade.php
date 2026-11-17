<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $organisation->name }}</title>
    <link rel="icon" type="image/png" href="{{ asset_v('images/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/invoice.css') }}">
    <style>
        .stay-list { width: 100%; margin: 14px 0; border-collapse: collapse; font-size: 13px; }
        .stay-list th, .stay-list td { padding: 6px 8px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        .stay-list thead th { border-bottom: 2px solid currentColor; }
        .stay-list .right { text-align: right; white-space: nowrap; }
        .stay-list tfoot td { border-top: 2px solid currentColor; font-weight: bold; }
    </style>
</head>
<body>
<div class="print-toolbar">
    <a class="btn ghost" href="{{ $backUrl }}">{{ __('Retour') }}</a>
    <x-lang-switch />
    <button type="button" class="btn" onclick="window.print()">{{ __('Imprimer') }}</button>
</div>

@include('stays.report-sheet')
</body>
</html>
