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
    <span class="soft small">{{ __(':v1 facture(s)', ['v1' => $invoices->count()]) }}</span>
    <x-lang-switch />
    <button type="button" class="btn" onclick="window.print()">{{ __('Imprimer') }}</button>
</div>

@foreach ($invoices as $invoice)
    @include('invoices.sheet', ['logoSrc' => route('settings.logo', ['v' => $organisation->updated_at?->timestamp])])
@endforeach
</body>
</html>
