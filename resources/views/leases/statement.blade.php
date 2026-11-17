<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('État du bail') }} — {{ $lease->tenant->name }}</title>
    <link rel="icon" type="image/png" href="{{ asset_v('images/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset_v('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/invoice.css') }}">
</head>
<body>
<div class="print-toolbar">
    <a class="btn ghost" href="{{ $backUrl }}">{{ __('Retour') }}</a>
    <form class="statement-period" method="GET">
        <label>{{ __('Du') }} <input type="month" name="from" value="{{ $from }}"></label>
        <label>{{ __('Au') }} <input type="month" name="to" value="{{ $to }}"></label>
        <button class="btn ghost">{{ __('Afficher') }}</button>
    </form>
    <a class="btn ghost" href="{{ route('leases.statement', $lease) }}">{{ __('Tout le bail') }}</a>
    <x-lang-switch />
    <a class="btn ghost" href="{{ route('leases.statement.pdf', ['lease' => $lease, 'from' => $from, 'to' => $to]) }}">{{ __('PDF') }}</a>
    <button type="button" class="btn" onclick="window.print()">{{ __('Imprimer') }}</button>
</div>

@include('leases.statement-sheet')
</body>
</html>
