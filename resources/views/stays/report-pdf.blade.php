<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('stays.pdf-style')
</head>
<body>
@include('stays.report-sheet')
</body>
</html>
