@if (\App\Support\PhoneNumber::isInternational($payment->lease->tenant->phone))
    <form class="inline" method="POST" action="{{ route('messages.payment', [$payment, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
        @csrf
        <button @class(['btn', 'small' => $small ?? false, 'ghost' => $payment->whatsapp_opened_at])><x-icon name="send" /> {{ ($small ?? false) ? __('WhatsApp') : __('Confirmer sur WhatsApp') }}</button>
    </form>
    <form class="inline" method="POST" action="{{ route('messages.payment', [$payment, 'sms']) }}" data-message-send>
        @csrf
        <button @class(['btn ghost', 'small' => $small ?? false])><x-icon name="message-square" /> {{ ($small ?? false) ? __('SMS') : __('Confirmer par SMS') }}</button>
    </form>
@endif
