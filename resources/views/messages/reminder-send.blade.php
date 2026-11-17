@if ($invoice->canBeSent())
    <form class="inline" method="POST" action="{{ route('messages.reminder', [$invoice, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
        @csrf
        <button @class(['btn small', 'ghost' => $invoice->reminder_opened_at])><x-icon name="send" /> {{ __('Rappel WhatsApp') }}</button>
    </form>
    <form class="inline" method="POST" action="{{ route('messages.reminder', [$invoice, 'sms']) }}" data-message-send>
        @csrf
        <button class="btn ghost small"><x-icon name="message-square" /> {{ __('Rappel SMS') }}</button>
    </form>
@else
    <button type="button" class="btn small" disabled title="{{ __('Loyer déjà réglé : envoi désactivé') }}"><x-icon name="send" /> {{ __('Rappel WhatsApp') }}</button>
@endif
