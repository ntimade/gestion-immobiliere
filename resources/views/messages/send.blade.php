@if ($invoice->canBeSent())
    <form class="inline" method="POST" action="{{ route('messages.open', [$invoice, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send data-pdf-url="{{ route('invoices.pdf', $invoice) }}">
        @csrf
        <button @class(['btn', 'small' => $small ?? false, 'ghost' => $invoice->whatsapp_opened_at])><x-icon name="send" /> {{ ($small ?? false) ? __('WhatsApp') : __('Envoyer sur WhatsApp') }}</button>
    </form>
@else
    <button type="button" @class(['btn', 'small' => $small ?? false]) disabled title="{{ __('Loyer déjà réglé : envoi désactivé') }}"><x-icon name="send" /> {{ ($small ?? false) ? __('WhatsApp') : __('Envoyer sur WhatsApp') }}</button>
@endif
<a @class(['btn ghost', 'small' => $small ?? false]) href="{{ route('invoices.pdf', $invoice) }}" download data-pdf-share title="{{ __('Partager ou télécharger la facture en PDF') }}"><x-icon name="receipt" /> {{ __('PDF') }}</a>
@if ($invoice->canBeSent())
    {{-- Pas de cible _blank : le lien sms: ouvre l'application de messages sans laisser d'onglet vide. --}}
    <form class="inline" method="POST" action="{{ route('messages.open', [$invoice, 'sms']) }}" data-message-send>
        @csrf
        <button @class(['btn', 'small' => $small ?? false, 'ghost' => $invoice->sms_opened_at || ! $invoice->whatsapp_opened_at])><x-icon name="message-square" /> {{ ($small ?? false) ? __('SMS') : __('Envoyer par SMS') }}</button>
    </form>
@else
    <form class="inline">
        <button type="button" @class(['btn', 'small' => $small ?? false]) disabled title="{{ __('Loyer déjà réglé : envoi désactivé') }}"><x-icon name="message-square" /> {{ ($small ?? false) ? __('SMS') : __('Envoyer par SMS') }}</button>
    </form>
@endif
