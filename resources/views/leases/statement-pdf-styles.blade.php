{{-- Le moteur PDF ne gère ni flex ni grid : la mise en page repose sur display: table. --}}
<style>
    @page { size: A4; margin: 14mm; }
    body { margin: 0; color: #111; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.4; }
    .invoice span, .invoice strong { display: block; }
    .invoice-head { display: table; width: 100%; padding-bottom: 12px; border-bottom: 2px solid #111; }
    .invoice-org, .invoice-title { display: table-cell; width: 50%; vertical-align: top; }
    .invoice-org strong { font-size: 13px; }
    .invoice-logo { display: block; max-width: 180px; max-height: 80px; margin-bottom: 8px; }
    .invoice-title { text-align: right; }
    .invoice-title h1 { margin: 0 0 4px; font-size: 20px; }
    .invoice-parties { display: table; width: 100%; margin: 14px 0; }
    .invoice-parties > div { display: table-cell; width: 50%; vertical-align: top; }
    .invoice-parties h2 { margin: 0 0 3px; color: #555; font-size: 9px; font-weight: bold; text-transform: uppercase; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 5px 7px; }
    .invoice-lines { margin-bottom: 12px; }
    .invoice-lines th, .invoice-lines td { border: 1px solid #999; text-align: right; }
    .invoice-lines th { font-size: 9px; }
    .invoice-lines .left { text-align: left; }
    .invoice-lines tr { page-break-inside: avoid; }
    .invoice-totals { width: 60%; margin-left: 40%; margin-bottom: 12px; }
    .invoice-totals th { border-bottom: 1px solid #ddd; font-weight: normal; text-align: left; }
    .invoice-totals td { border-bottom: 1px solid #ddd; text-align: right; white-space: nowrap; }
    .invoice-totals .subtotal th, .invoice-totals .subtotal td { font-weight: bold; }
    .invoice-totals .grand-total th, .invoice-totals .grand-total td { border-top: 2px solid #111; border-bottom: 2px solid #111; font-size: 12px; font-weight: bold; }
    .statement-title { margin: 12px 0 6px; font-size: 11px; }
    .history-lease { margin: 16px 0 8px; padding: 5px 8px; background: #eee; font-size: 12px; }
    .history-lease small { display: block; font-size: 9px; font-weight: normal; }
    .invoice-note { margin-top: 12px; color: #333; font-size: 9px; }
</style>
