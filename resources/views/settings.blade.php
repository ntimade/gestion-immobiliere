@extends('layouts.app')
@section('title', __('Paramètres'))

@section('content')
    <div class="page-head"><h1>{{ __('Paramètres') }}</h1></div>

    <nav class="subnav" aria-label="{{ __('Sections des paramètres') }}">
        @if (auth()->user()->isAdmin())
            <a href="#section-organisation">{{ __('Compte') }}</a>
            <a href="#section-logo">{{ __('Logo') }}</a>
        @endif
        <a href="#section-mot-de-passe">{{ __('Mot de passe') }}</a>
        <a href="#section-questions">{{ __('Questions de sécurité') }}</a>
    </nav>

    @if (auth()->user()->isAdmin())
    <div class="card narrow" id="section-organisation">
        <h2>{{ __('Compte et facturation') }}</h2>
        <form method="POST" action="{{ route('settings.update') }}">
            @csrf @method('PUT')
            <x-field name="name" :label="__('Nom affiché sur les factures')" :value="$organisation->name" :required="true" />
            <div class="form-grid">
                <x-field name="phone" :label="__('Téléphone')" type="tel" :value="$organisation->phone" />
                <x-field name="address" :label="__('Adresse')" :value="$organisation->address" />
            </div>
            <x-field name="phone_country" :label="__('Pays')" :help="__('Sert à mettre les numéros de vos locataires au format international.')">
                <select id="phone_country" name="phone_country">
                    <option value="">{{ __('Choisir…') }}</option>
                    @foreach (config('phone_countries') as $iso => [$label, $code])
                        <option value="{{ $iso }}" @selected(old('phone_country', $organisation->phone_country) === $iso)>{{ __($label) }} (+{{ $code }})</option>
                    @endforeach
                </select>
            </x-field>
            <div class="form-grid">
                <x-field name="currency" :label="__('Devise')" :required="true"
                         :help="$currencyLocked ? __('Non modifiable : des loyers sont déjà facturés dans cette devise.') : null">
                    @if ($currencyLocked)
                        <input type="hidden" name="currency" value="{{ $organisation->currency }}">
                    @endif
                    <select id="currency" @unless ($currencyLocked) name="currency" @endunless @disabled($currencyLocked) required>
                        @foreach ($currencies as $code => [$label])
                            <option value="{{ $code }}" @selected(old('currency', $organisation->currency) === $code)>{{ $code }} — {{ __($label) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field name="reminder_days" :label="__('Rappel avant l\'échéance (jours)')" type="number" min="1" max="15" step="1" :required="true"
                         :value="$organisation->reminder_days" :help="__('Le locataire apparaît dans la liste des rappels à partir de ce nombre de jours avant son échéance.')" />
                <x-field name="deposit_months" :label="__('Caution par défaut (mois de loyer)')" type="number" min="0" max="24" step="1" :required="true"
                         :value="$organisation->deposit_months" :help="__('Proposée à chaque entrée et négociable pour chaque client. Mettez 0 pour ne pas demander de caution par défaut.')" />
                <x-field name="legal_notice_after":label="__('Procédure judiciaire après un impayé de')" type="number" min="1" max="365" step="1"
                         :value="$organisation->legal_notice_after" :help="__('Comptés depuis l\'échéance impayée la plus ancienne. Laissez vide pour ne proposer aucune notification.')" />
                <x-field name="legal_notice_unit" :label="__('Unité du délai')">
                    <select id="legal_notice_unit" name="legal_notice_unit">
                        <option value="months" @selected(old('legal_notice_unit', $organisation->legal_notice_unit) === 'months')>{{ __('Mois') }}</option>
                        <option value="days" @selected(old('legal_notice_unit', $organisation->legal_notice_unit) === 'days')>{{ __('Jours') }}</option>
                    </select>
                </x-field>
            </div>
            <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
        </form>
    </div>

    <div class="card narrow" id="section-logo">
        <h2>{{ __('Logo') }}</h2>
        <p class="soft small">{{ __('Il apparaît sur vos factures et dans le menu. Formats PNG, JPG ou WEBP, 2 Mo au maximum.') }}</p>
        @if ($organisation->logo_path)
            <img class="logo-preview" src="{{ route('settings.logo', ['v' => $organisation->updated_at?->timestamp]) }}" alt="{{ __('Logo actuel') }}">
        @endif
        <form method="POST" action="{{ route('settings.logo.update') }}" enctype="multipart/form-data">
            @csrf
            <x-field name="logo" :label="$organisation->logo_path ? __('Remplacer le logo') : __('Choisir un logo')">
                <input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
            </x-field>
            <button type="submit" class="btn">{{ __('Enregistrer le logo') }}</button>
        </form>
        @if ($organisation->logo_path)
            <form class="inline" method="POST" action="{{ route('settings.logo.destroy') }}" data-confirm="{{ __('Retirer le logo ?') }}" onsubmit="return confirm(this.dataset.confirm)">
                @csrf @method('DELETE')
                <p style="margin: 10px 0 0"><button class="btn danger small">{{ __('Retirer le logo') }}</button></p>
            </form>
        @endif
    </div>

    @endif

    <div class="card narrow" id="section-mot-de-passe">
        <h2>{{ __('Changer mon mot de passe') }}</h2>
        @if (auth()->user()->must_change_password)
            <div class="flash error">{{ __('Choisissez un nouveau mot de passe pour continuer.') }}</div>
        @endif
        <form method="POST" action="{{ route('settings.password') }}">
            @csrf @method('PUT')
            <x-field name="current_password" :label="__('Mot de passe actuel')" type="password" :required="true" errorBag="password" autocomplete="current-password" />
            <div class="form-grid">
                <x-field name="password" :label="__('Nouveau mot de passe')" type="password" :required="true" minlength="8" errorBag="password" autocomplete="new-password" />
                <x-field name="password_confirmation" :label="__('Confirmer')" type="password" :required="true" autocomplete="new-password" />
            </div>
            <button type="submit" class="btn">{{ __('Changer le mot de passe') }}</button>
        </form>
    </div>

    <div class="card narrow" id="section-questions">
        <h2>{{ __('Questions de sécurité') }}</h2>
        <p class="soft small">{{ __('Elles permettent de récupérer votre mot de passe en cas d\'oubli. Choisissez des réponses que vous seul connaissez.') }}</p>
        @if (! $securityQuestions)
            <div class="flash info">{{ __('Aucune question enregistrée : en cas d\'oubli, seul le support pourra vous aider.') }}</div>
        @endif
        <form method="POST" action="{{ route('settings.security') }}">
            @csrf @method('PUT')
            @include('auth.security-fields', ['bag' => 'security', 'saved' => $securityQuestions])
            <x-field name="security_current_password" :label="__('Mot de passe actuel')" type="password" :required="true" errorBag="security" autocomplete="current-password" />
            <button type="submit" class="btn">{{ __('Enregistrer les questions') }}</button>
        </form>
    </div>
@endsection
