<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('État du bail') }} — {{ $lease->tenant->name }}</title>
    @include('leases.statement-pdf-styles')
</head>
<body>
@include('leases.statement-sheet')
</body>
</html>
