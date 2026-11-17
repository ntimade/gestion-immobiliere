<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Notification de procédure judiciaire') }} — {{ $lease->tenant->name }}</title>
    {{-- Le moteur PDF ne gère ni flex ni grid : la mise en page repose sur display: table. --}}
    <style>
        @page { size: A4; margin: 15mm 17mm; }
        body { margin: 0; color: #1d1512; font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; line-height: 1.45; }
        .head { display: table; width: 100%; padding-bottom: 9px; border-bottom: 2.5px solid #b0452a; }
        .head > div { display: table-cell; vertical-align: top; }
        .head span, .head strong { display: block; }
        .org strong { font-size: 12px; }
        .logo { display: block; max-width: 130px; max-height: 56px; margin-bottom: 4px; }
        .title { width: 54%; text-align: right; }
        .title h1 { margin: 0 0 3px; color: #b0452a; font-size: 13.5px; line-height: 1.2; text-transform: uppercase; }
        .to { width: 50%; margin: 11px 0 11px 50%; }
        .to span, .to strong { display: block; }
        .to .label { color: #7a665c; font-size: 8px; text-transform: uppercase; }
        .to strong { font-size: 10.5px; }
        .subject { margin-bottom: 10px; padding: 6px 9px; border-left: 3px solid #b0452a; background: #f8f1e7; font-weight: bold; }
        p { margin: 0 0 7px; }
        h2 { margin: 10px 0 4px; color: #b0452a; font-size: 10px; }
        table.lines { width: 100%; margin-bottom: 7px; border-collapse: collapse; }
        .lines th, .lines td { padding: 3px 6px; border-bottom: 1px solid #d9cabd; text-align: right; }
        .lines th { background: #f8f1e7; color: #5c4a41; font-size: 8.5px; }
        .lines tr { page-break-inside: avoid; }
        .lines .left { text-align: left; }
        .lines .right { text-align: right; }
        .lines .total th, .lines .total td { border-top: 2px solid #1d1512; border-bottom: 2px solid #1d1512; background: #fff; color: #1d1512; font-size: 10.5px; font-weight: bold; }
        .procedure { padding: 6px 9px; border: 1px solid #b0452a; font-weight: bold; }
        .signatures { display: table; width: 100%; margin-top: 12px; page-break-inside: avoid; }
        .signatures > div { display: table-cell; width: 50%; vertical-align: top; }
        .signatures span, .signatures strong { display: block; }
        .signatures .line { height: 42px; }
        .tenant-receipt { padding-left: 14px; border-left: 1px solid #d9cabd; }
        .soft { color: #7a665c; font-size: 8.5px; }
    </style>
</head>
<body>
    @php($section = 0)
    <div class="head">
        <div class="org">
            @if ($organisation->logo_path && $logoSrc)
                <img class="logo" src="{{ $logoSrc }}" alt="{{ __('Logo de :v1', ['v1' => $organisation->name]) }}">
            @endif
            <strong>{{ $organisation->name }}</strong>
            @if ($organisation->address)<span>{{ $organisation->address }}</span>@endif
            @if ($organisation->phone)<span>{{ __('Tél. : :v1', ['v1' => $organisation->phone]) }}</span>@endif
        </div>
        <div class="title">
            <h1>{{ __('Notification de procédure judiciaire') }}</h1>
            <span>{{ __('Réf. : :v1', ['v1' => $notice->reference]) }}</span>
            <span>{{ __('Le :v1', ['v1' => now()->format('d/m/Y')]) }}</span>
        </div>
    </div>

    <div class="to">
        <span class="label">{{ __('À l\'attention de') }}</span>
        <strong>{{ trim($lease->tenant->title().' '.$lease->tenant->name) }}</strong>
        <span>{{ $lease->unit->label() }}</span>
        @if ($lease->unit->property->address)<span>{{ $lease->unit->property->address }}</span>@endif
        @if ($lease->tenant->phone)<span>{{ __('Tél. : :v1', ['v1' => $lease->tenant->phone]) }}</span>@endif
    </div>

    <p class="subject">{{ __('Objet : début d\'une procédure judiciaire pour loyers impayés') }}</p>

    <p>{{ $lease->tenant->title() ?: __('Madame, Monsieur') }},</p>

    <p>
        {{ $lease->isActive()
            ? __('Vous êtes locataire du logement :v1 depuis le :v2, pour un loyer de :v3 payable le :v4 de chaque mois.', ['v1' => $lease->unit->label(), 'v2' => $lease->start_date->format('d/m/Y'), 'v3' => money($lease->rent), 'v4' => $lease->due_day])
            : __('Vous avez été locataire du logement :v1 du :v2 au :v3, pour un loyer de :v4 par mois.', ['v1' => $lease->unit->label(), 'v2' => $lease->start_date->format('d/m/Y'), 'v3' => $lease->end_date->format('d/m/Y'), 'v4' => money($lease->rent)]) }}
    </p>

    <h2>{{ ++$section }}. {{ __('Sommes dues') }}</h2>
    <p>{{ __('À la date de cette lettre, les loyers suivants restent impayés après leur échéance :') }}</p>
    <table class="lines">
        <thead><tr><th class="left">{{ __('Mois') }}</th><th>{{ __('Échéance') }}</th><th>{{ __('Retard') }}</th><th>{{ __('Montant') }}</th><th>{{ __('Versé') }}</th><th>{{ __('Reste') }}</th></tr></thead>
        <tbody>
            @foreach ($notice->invoices as $invoice)
                <tr>
                    <td class="left">{{ $invoice->label() }}</td>
                    <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                    <td>{{ __(':v1 j', ['v1' => (int) $invoice->due_date->diffInDays(today())]) }}</td>
                    <td>{{ money($invoice->amount) }}</td>
                    <td>{{ money($invoice->paid_amount) }}</td>
                    <td>{{ money($invoice->balance()) }}</td>
                </tr>
            @endforeach
            <tr class="total"><th class="left" colspan="5">{{ __('Total dû') }}</th><td>{{ money($notice->total) }}</td></tr>
        </tbody>
    </table>
    @if ($lastPayment)
        <p class="soft">{{ __('Dernier versement enregistré : :v1 le :v2.', ['v1' => money($lastPayment->amount), 'v2' => $lastPayment->paid_at->format('d/m/Y')]) }}</p>
    @endif

    @if ($notice->history->isNotEmpty())
        <h2>{{ ++$section }}. {{ __('Démarches déjà effectuées') }}</h2>
        <table class="lines">
            <thead><tr><th class="left">{{ __('Date') }}</th><th class="left">{{ __('Message') }}</th><th class="left">{{ __('Mois concernés') }}</th></tr></thead>
            <tbody>
                @foreach ($notice->history as $entry)
                    <tr>
                        <td class="left">{{ $entry->date->format('d/m/Y') }}</td>
                        <td class="left">{{ $entry->label }}</td>
                        <td class="left">{{ $entry->months }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ ++$section }}. {{ __('Caution') }}</h2>
    @include('leases.deposit-trace', ['tableClass' => 'lines', 'noteClass' => 'soft'])
    <p class="soft">{{ __('Encore détenue : :v1. La caution n\'est pas déduite du total dû ci-dessus.', ['v1' => money($lease->depositHeld())]) }}</p>

    <h2>{{ ++$section }}. {{ __('Procédure engagée') }}</h2>
    <p class="procedure">{{ \App\Services\LegalNotice::procedureSentence($lease) }}</p>

    <h2>{{ ++$section }}. {{ __('Pour régulariser') }}</h2>
    <p>
        {{ __('Tout règlement reçu sera déduit des sommes dues.') }}
        {{ $organisation->phone
            ? __('Pour régler ou pour toute question, contactez-nous au :v1.', ['v1' => $organisation->phone])
            : __('Pour régler ou pour toute question, contactez-nous.') }}
    </p>

    <p>{{ __('Veuillez agréer l\'expression de nos salutations distinguées.') }}</p>

    <div class="signatures">
        <div>
            <span class="soft">{{ __('Le bailleur') }}</span>
            <strong>{{ $organisation->name }}</strong>
            <span class="line"></span>
            <span class="soft">{{ __('Signature') }}</span>
        </div>
        <div class="tenant-receipt">
            <span class="soft">{{ __('En cas de remise en main propre') }}</span>
            <strong>{{ __('Reçu le ____ / ____ / ________') }}</strong>
            <span class="line"></span>
            <span class="soft">{{ __('Signature du locataire') }}</span>
        </div>
    </div>
</body>
</html>
