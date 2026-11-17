{{-- Le préavis et l'état des lieux de sortie : la facturation s'arrête au départ, la caution attend la visite. --}}
@php($canMessage = \App\Support\PhoneNumber::isInternational($lease->tenant->phone))
<div class="card" id="preavis">
    <h2>{{ __('Préavis de départ') }}</h2>

    @if (! $lease->onNotice())
        <p class="soft small">{{ __('Le locataire annonce son départ : la facturation s\'arrête après le mois du départ et le logement est libre le lendemain. La caution est réglée plus tard, après l\'état des lieux de sortie.') }}</p>
        <form method="POST" action="{{ route('leases.notice', $lease) }}">
            @csrf
            <div class="form-grid">
                <x-field name="notice_given_on" :label="__('Préavis reçu le')" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" :required="true" />
                <x-field name="notice_end_date" :label="__('Date de départ')" type="date" :value="now()->addMonthNoOverflow()->toDateString()" :required="true"
                         :help="__('Par défaut, un mois après le préavis.')" />
            </div>
            <button type="submit" class="btn">{{ __('Enregistrer le préavis') }}</button>
        </form>
    @else
        @if ($lease->exitToFinalize())
            <div class="flash error">{{ __('Le départ prévu est passé : enregistrez la sortie pour régler la caution.') }}</div>
        @endif
        <dl class="detail">
            <div><dt>{{ __('Préavis reçu le') }}</dt><dd>{{ $lease->notice_given_on->format('d/m/Y') }}</dd></div>
            <div><dt>{{ __('Départ') }}</dt><dd><strong>{{ $lease->notice_end_date->format('d/m/Y') }}</strong></dd></div>
            <div><dt>{{ __('Logement libre le') }}</dt><dd>{{ $lease->notice_end_date->copy()->addDay()->format('d/m/Y') }}</dd></div>
            <div><dt>{{ __('Reste à payer avant le départ') }}</dt><dd>{{ money($lease->dueUntilDeparture()) }}</dd></div>
        </dl>
        <div class="actions" style="margin: 10px 0">
            @if ($canMessage)
                <form class="inline" method="POST" action="{{ route('messages.notice', [$lease, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
                    @csrf
                    <button @class(['btn small', 'ghost' => $lease->notice_opened_at])><x-icon name="send" /> {{ __('Confirmer sur WhatsApp') }}</button>
                </form>
                <form class="inline" method="POST" action="{{ route('messages.notice', [$lease, 'sms']) }}" data-message-send>
                    @csrf
                    <button class="btn ghost small"><x-icon name="message-square" /> {{ __('Confirmer par SMS') }}</button>
                </form>
            @endif
            <form class="inline" method="POST" action="{{ route('leases.notice.cancel', $lease) }}" data-confirm="{{ __('Annuler ce préavis ? La facturation reprendra.') }}" onsubmit="return confirm(this.dataset.confirm)">
                @csrf
                <button class="btn ghost small">{{ __('Annuler le préavis') }}</button>
            </form>
        </div>

        <h3>{{ __('État des lieux de sortie') }}</h3>
        <p class="soft small">{{ __('Fixez la visite pendant le préavis et invitez le locataire. Après la visite, notez les constats et le coût des réfections : ils prérempliront le remboursement de la caution à la sortie.') }}</p>
        <form method="POST" action="{{ route('leases.inspection', $lease) }}">
            @csrf
            <div class="form-grid">
                <x-field name="inspection_at" :label="__('Date et heure de la visite')" type="datetime-local" :value="$lease->inspection_at?->format('Y-m-d\TH:i')" />
                <x-field name="inspection_cost" :label="__('Coût des réfections')" type="number" step="any" min="0" :value="$lease->inspection_cost !== null ? (float) $lease->inspection_cost : null"
                         :help="__('Montant à retenir sur la caution. 0 si rien à réparer.')" />
            </div>
            <x-field name="inspection_report" :label="__('Constats')">
                <textarea id="inspection_report" name="inspection_report" rows="3" placeholder="{{ __('Peinture du salon abîmée, robinet de la cuisine à changer…') }}">{{ old('inspection_report', $lease->inspection_report) }}</textarea>
            </x-field>
            <div class="actions">
                <button type="submit" class="btn">{{ __('Enregistrer l\'état des lieux') }}</button>
            </div>
        </form>
        @if ($lease->inspection_at && $canMessage)
            <div class="actions" style="margin-top: 10px">
                <form class="inline" method="POST" action="{{ route('messages.inspection', [$lease, 'whatsapp']) }}" target="_blank" rel="noopener" data-message-send>
                    @csrf
                    <button @class(['btn small', 'ghost' => $lease->inspection_opened_at])><x-icon name="send" /> {{ __('Inviter sur WhatsApp') }}</button>
                </form>
                <form class="inline" method="POST" action="{{ route('messages.inspection', [$lease, 'sms']) }}" data-message-send>
                    @csrf
                    <button class="btn ghost small"><x-icon name="message-square" /> {{ __('Inviter par SMS') }}</button>
                </form>
            </div>
            @if ($lease->inspection_opened_at)<p class="soft small">{{ __('Invitation ouverte le :v1', ['v1' => $lease->inspection_opened_at->format('d/m/Y')]) }}</p>@endif
        @endif

        <div class="actions" style="margin-top: 14px">
            <a class="btn danger" href="{{ route('leases.exit', $lease) }}">{{ __('Enregistrer la sortie et la caution') }}</a>
        </div>
    @endif
</div>
