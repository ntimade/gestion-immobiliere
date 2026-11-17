@extends('layouts.app')
@section('title', __('Entrée d\'un locataire'))

@section('content')
    <div class="page-head"><h1>{{ __('Entrée d\'un locataire') }}</h1></div>
    <p class="soft">{{ $unit->label() }} · {{ $unit->typeLabel() }}</p>

    <div class="card narrow">
        <form method="POST" action="{{ route('leases.store', $unit) }}">
            @csrf
            @error('unit_id')<div class="flash error">{{ $message }}</div>@enderror

            <h2>{{ __('Le locataire') }}</h2>
            @php($mode = old('tenant_mode', 'new'))
            @if ($tenants->isNotEmpty())
                <div class="field">
                    <div class="choices">
                        <label class="choice"><input type="radio" name="tenant_mode" value="new" @checked($mode === 'new')> {{ __('Nouveau locataire') }}</label>
                        <label class="choice"><input type="radio" name="tenant_mode" value="existing" @checked($mode === 'existing')> {{ __('Locataire déjà enregistré') }}</label>
                    </div>
                </div>
                <div id="tenant-existing">
                    <x-field name="tenant_id" :label="__('Locataire')" :required="true">
                        <select id="tenant_id" name="tenant_id" required>
                            <option value="">{{ __('Choisir…') }}</option>
                            @foreach ($tenants as $tenant)
                                <option value="{{ $tenant->id }}" @selected(old('tenant_id') == $tenant->id)>{{ $tenant->name }}{{ $tenant->phone ? ' · '.$tenant->phone : '' }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
            @else
                <input type="hidden" name="tenant_mode" value="new">
            @endif

            <div id="tenant-new">
                <div class="field">
                    <label for="tenant_name">{{ __('Nom') }} <span class="req">*</span></label>
                    <input id="tenant_name" name="tenant[name]" type="text" value="{{ old('tenant.name') }}" required>
                    @error('tenant.name')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="tenant_phone">{{ __('Téléphone') }}</label>
                        <input id="tenant_phone" name="tenant[phone]" type="tel" value="{{ old('tenant.phone') }}">
                        <div class="help">{{ __('Nécessaire pour les rappels WhatsApp et SMS.') }}</div>
                        @error('tenant.phone')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="tenant_gender">{{ __('Sexe') }}</label>
                        <select id="tenant_gender" name="tenant[gender]">
                            <option value="">{{ __('Non renseigné (Monsieur/Madame)') }}</option>
                            <option value="M" @selected(old('tenant.gender') === 'M')>{{ __('Homme (Monsieur)') }}</option>
                            <option value="F" @selected(old('tenant.gender') === 'F')>{{ __('Femme (Madame)') }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="tenant_email">{{ __('E-mail') }}</label>
                        <input id="tenant_email" name="tenant[email]" type="email" value="{{ old('tenant.email') }}">
                        @error('tenant.email')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="tenant_id_number">{{ __('N° de pièce d\'identité') }}</label>
                        <input id="tenant_id_number" name="tenant[id_number]" type="text" value="{{ old('tenant.id_number') }}">
                    </div>
                </div>
            </div>

            <h2>{{ __('Le loyer') }}</h2>
            @php($entryDay = $earliestStart ?? now())
            @if ($earliestStart)
                <p class="flash info">{{ __('Le locataire actuel a donné son préavis : l\'entrée est possible à partir du :v1.', ['v1' => $earliestStart->format('d/m/Y')]) }}</p>
            @endif
            <div class="form-grid">
                <x-field name="start_date" :label="__('Date d\'entrée')" type="date" :value="$entryDay->toDateString()" :required="true"
                         :help="__('Pour régulariser un client déjà en place, saisissez sa vraie date d\'entrée, même ancienne.')" />
                <x-field name="due_day" :label="__('Jour d\'échéance chaque mois')" type="number" min="1" max="31" step="1" :required="true" :value="$entryDay->day"
                         :help="__('Par défaut le jour d\'entrée. Le 31 devient le dernier jour des mois plus courts.')" />
            </div>
            <div class="form-grid">
                <x-field name="rent" :label="__('Loyer par mois')" type="number" step="any" min="0" :required="true" :value="(float) $unit->rent" />
                <x-field name="first_period" :label="__('Premier mois à facturer')" type="month" :value="$entryDay->format('Y-m')"
                         :help="__('Pour un locataire déjà en place, choisissez le premier mois à suivre ici.')" />
            </div>
            <x-field name="billing_months" :label="__('Cycle de facturation (en mois)')" type="number" min="1" max="24" step="1" :required="true" :value="1"
                     :help="__('1 : chaque mois, 2 : tous les 2 mois, 3 : chaque trimestre, 6 : chaque semestre, 12 : chaque année. Le cycle commence après les mois payés d\'avance.')" />

            @php($defaultMonths = (int) auth()->user()->organisation->deposit_months)
            <h2>{{ __('La caution') }}</h2>
            <p class="soft small">{{ __('Elle se verse une seule fois, à l\'entrée.') }}</p>
            <div class="field">
                <input type="hidden" name="deposit_enabled" value="0">
                <label class="choice"><input type="checkbox" id="deposit_enabled" name="deposit_enabled" value="1" @checked(old('deposit_enabled', $defaultMonths > 0 ? '1' : '0') == '1')> {{ __('Demander une caution à ce client') }}</label>
            </div>
            <div class="form-grid" id="deposit-fields">
                <x-field name="deposit_months" :label="__('Caution en mois de loyer')" type="number" min="0" max="24" step="1" :value="$defaultMonths ?: 2"
                         :help="__('Négociable pour chaque client.')" />
                <x-field name="deposit_amount" :label="__('Montant de la caution')" type="number" step="any" min="0" :value="(float) $unit->rent * ($defaultMonths ?: 2)" readonly
                         :help="__('Calculé : nombre de mois × loyer.')" />
            </div>

            <h2>{{ __('Le versement d\'entrée') }}</h2>
            <p class="soft small">{{ __('Exemple : pour 10 mois versés dont 2 de caution, la caution reçoit 2 mois et les loyers 8 mois payés d\'avance. Laissez vide si rien n\'est versé en loyers aujourd\'hui. Une fois l\'avance terminée, le loyer repasse au mois par mois.') }}</p>
            <div class="form-grid">
                <x-field name="entry_months" :label="__('Mois versés en tout, caution comprise')" type="number" min="1" step="1" />
                <x-field name="deposit_paid_at" :label="__('Date du versement')" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}"
                         :help="__('La caution est retenue, le reste part dans les loyers. Si le client a payé plusieurs fois, ajoutez les autres paiements sur sa fiche, avec leur date.')" />
            </div>
            <div class="form-grid">
                <x-field name="entry_method" :label="__('Mode de paiement')" :placeholder="__('Espèces, Mobile Money…')" />
                <x-field name="entry_reference" :label="__('Référence')" />
            </div>
            <div class="flash info" id="entry-recap" hidden></div>

            <x-field name="notes" :label="__('Observation')" />

            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer l\'entrée') }}</button>
                <a class="btn ghost" href="{{ route('units.show', $unit) }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var startDate = document.getElementById('start_date');
            var dueDay = document.getElementById('due_day');
            var firstPeriod = document.getElementById('first_period');
            var paymentDate = document.getElementById('deposit_paid_at');
            var today = @json(now()->toDateString());
            var currentPeriod = today.slice(0, 7);
            var dueTouched = false;
            var periodTouched = false;
            var paymentTouched = false;

            dueDay.addEventListener('input', function () { dueTouched = true; });
            firstPeriod.addEventListener('input', function () { periodTouched = true; });
            paymentDate.addEventListener('input', function () { paymentTouched = true; });

            // Le jour d'entrée devient l'échéance, le premier mois et la date du versement, tant qu'ils n'ont pas été choisis.
            startDate.addEventListener('change', function () {
                if (!startDate.value) { return; }
                if (!dueTouched) { dueDay.value = parseInt(startDate.value.slice(8), 10); }
                if (!periodTouched) { firstPeriod.value = startDate.value.slice(0, 7); }
                firstPeriod.min = startDate.value.slice(0, 7);
                if (!paymentTouched) { paymentDate.value = startDate.value > today ? today : startDate.value; }
            });

            var rent = document.getElementById('rent');
            var enabled = document.getElementById('deposit_enabled');
            var depositFields = document.getElementById('deposit-fields');
            var depositMonths = document.getElementById('deposit_months');
            var depositAmount = document.getElementById('deposit_amount');
            var entryMonths = document.getElementById('entry_months');
            var recap = document.getElementById('entry-recap');
            var currency = @json(auth()->user()->organisation->currency);
            var locale = document.documentElement.lang || 'fr';
            var texts = {
                deposit: @json(__('Caution : :v1')),
                rent: @json(__('Loyers d\'avance : :v1 mois, soit :v2, de :v3 à :v4')),
                noRent: @json(__('Loyers d\'avance : aucun')),
                until: @json(__('Loyer payé jusqu\'au :v1')),
                total: @json(__('Total reçu : :v1')),
                tooLow: @json(__('Le versement d\'entrée est inférieur à la caution.')),
                past: @json(__('Régularisation : :v1 mois déjà passés ne sont pas couverts et seront facturés comme impayés. S\'ils sont réglés, indiquez-le ici ou choisissez un premier mois plus récent.'))
            };
            function money(value) {
                return Math.round(value * 1000) / 1000 === 0 ? '0 ' + currency : value.toLocaleString(locale) + ' ' + currency;
            }

            function monthLabel(start, offset) {
                var parts = start.split('-');
                return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1 + offset, 1));
            }

            // La caution suit toujours le loyer et le nombre de mois.
            function refreshEntry() {
                var active = enabled.checked;
                depositFields.hidden = !active;
                depositMonths.disabled = !active;
                depositAmount.disabled = !active;

                var monthlyRent = parseFloat(rent.value) || 0;
                if (active) {
                    depositAmount.value = monthlyRent * (parseInt(depositMonths.value, 10) || 0);
                }

                var months = parseInt(entryMonths.value, 10) || 0;
                var deposit = active ? (parseFloat(depositAmount.value) || 0) : 0;
                var total = monthlyRent * months;
                var advance = total - deposit;

                // Les mois écoulés avant ce mois-ci, que le versement ne couvre pas, deviendront des impayés.
                var elapsed = 0;
                if (firstPeriod.value && firstPeriod.value < currentPeriod) {
                    elapsed = (parseInt(currentPeriod.slice(0, 4), 10) - parseInt(firstPeriod.value.slice(0, 4), 10)) * 12
                        + parseInt(currentPeriod.slice(5, 7), 10) - parseInt(firstPeriod.value.slice(5, 7), 10);
                }
                var uncovered = Math.max(elapsed - (months > 0 && monthlyRent > 0 && advance > 0 ? Math.floor(advance / monthlyRent) : 0), 0);

                recap.hidden = months === 0 && uncovered === 0;
                recap.textContent = '';
                if (recap.hidden) { return; }

                function addLine(text) {
                    var row = document.createElement('div');
                    row.textContent = text;
                    recap.appendChild(row);
                }

                if (months === 0) {
                    addLine(texts.past.replace(':v1', uncovered));
                    return;
                }

                if (advance < 0) {
                    addLine(texts.tooLow);
                    return;
                }

                var paidMonths = monthlyRent > 0 ? Math.ceil(advance / monthlyRent) : 0;
                var lines = [
                    texts.deposit.replace(':v1', money(deposit)),
                    paidMonths > 0 && firstPeriod.value
                        ? texts.rent.replace(':v1', advance / monthlyRent).replace(':v2', money(advance)).replace(':v3', monthLabel(firstPeriod.value, 0)).replace(':v4', monthLabel(firstPeriod.value, paidMonths - 1))
                        : texts.noRent,
                    texts.total.replace(':v1', money(total))
                ];

                // La veille de l'échéance qui suit le dernier mois entièrement réglé.
                var fullMonths = monthlyRent > 0 ? Math.floor(advance / monthlyRent) : 0;
                if (fullMonths > 0 && firstPeriod.value) {
                    var parts = firstPeriod.value.split('-');
                    var year = parseInt(parts[0], 10);
                    var month = parseInt(parts[1], 10) - 1 + fullMonths;
                    var lastDay = new Date(year, month + 1, 0).getDate();
                    var until = new Date(year, month, Math.min(parseInt(dueDay.value, 10) || 1, lastDay) - 1);
                    lines.splice(2, 0, texts.until.replace(':v1', until.toLocaleDateString('fr-FR')));
                }

                lines.forEach(addLine);
                if (uncovered > 0) { addLine(texts.past.replace(':v1', uncovered)); }
            }

            [rent, depositMonths, entryMonths, firstPeriod, dueDay].forEach(function (field) { field.addEventListener('input', refreshEntry); });
            enabled.addEventListener('change', refreshEntry);
            startDate.addEventListener('change', refreshEntry);
            firstPeriod.addEventListener('change', refreshEntry);
            refreshEntry();

            var existing = document.getElementById('tenant-existing');
            var fresh = document.getElementById('tenant-new');

            // Champs désactivés quand ils sont masqués : ils ne bloquent pas l'envoi du formulaire.
            function refresh() {
                var checked = document.querySelector('input[name="tenant_mode"]:checked');
                var useExisting = checked && checked.value === 'existing';
                if (existing) {
                    existing.hidden = !useExisting;
                    existing.querySelectorAll('select').forEach(function (field) { field.disabled = !useExisting; });
                }
                fresh.hidden = useExisting;
                fresh.querySelectorAll('input, select').forEach(function (field) { field.disabled = useExisting; });
            }

            document.querySelectorAll('input[name="tenant_mode"]').forEach(function (radio) {
                radio.addEventListener('change', refresh);
            });
            refresh();
        })();
    </script>
@endpush
