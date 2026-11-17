<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('stays.pdf-style')
</head>
<body>
@foreach ($stays as $stay)
    @include('stays.invoice-sheet')
@endforeach
</body>
</html>
