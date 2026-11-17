<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Historique du locataire') }} — {{ $tenant->name }}</title>
    @include('leases.statement-pdf-styles')
</head>
<body>
@include('tenants.history-sheet')
</body>
</html>
