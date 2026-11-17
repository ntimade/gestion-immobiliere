<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    {{-- Le moteur PDF ne gère ni flex ni grid : la mise en page repose sur display: table. --}}
    <style>
        @page { size: A4; margin: 14mm; }
        body { margin: 0; color: #111; font-family: "DejaVu Sans", sans-serif; font-size: 11px; line-height: 1.4; }
        .invoice span, .invoice strong { display: block; }
        .invoice-head { display: table; width: 100%; padding-bottom: 12px; border-bottom: 2px solid #111; }
        .invoice-org, .invoice-title { display: table-cell; width: 50%; vertical-align: top; }
        .invoice-org strong { font-size: 13px; }
        .invoice-logo { display: block; max-width: 180px; max-height: 80px; margin-bottom: 8px; }
        .invoice-title { text-align: right; }
        .invoice-title h1 { margin: 0; font-size: 22px; }
        .invoice-number { margin: 2px 0 4px; font-weight: bold; }
        .invoice-due { margin-top: 6px; }
        .invoice-parties { display: table; width: 100%; margin: 16px 0; }
        .invoice-parties > div { display: table-cell; width: 50%; vertical-align: top; }
        .invoice-parties h2 { margin: 0 0 3px; color: #555; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 7px 8px; }
        .invoice-totals { width: 60%; margin-left: 40%; }
        .invoice-totals th { border-bottom: 1px solid #ddd; font-weight: normal; text-align: left; }
        .invoice-totals td { border-bottom: 1px solid #ddd; text-align: right; white-space: nowrap; }
        .invoice-totals .subtotal th, .invoice-totals .subtotal td { font-weight: bold; }
        .invoice-totals .grand-total th, .invoice-totals .grand-total td { border-top: 2px solid #111; border-bottom: 2px solid #111; font-size: 13px; font-weight: bold; }
        .invoice-note { margin-top: 14px; color: #333; font-size: 10px; }
    </style>
</head>
<body>
@foreach ($invoices as $invoice)
    @include('invoices.sheet', ['logoSrc' => $organisation->logoDataUri()])
@endforeach
</body>
</html>
