@extends('layouts.support')
@section('title', __('Régler les plans'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Régler les plans') }}</h1>
        <a class="btn ghost" href="{{ route('support.subscriptions.index') }}">{{ __('Retour') }}</a>
    </div>

    <div class="card">
        <h2>{{ __('Plans d\'abonnement') }}</h2>
        <p class="soft small">{{ __('Seuls les plans actifs avec un prix sont proposés aux utilisateurs.') }}</p>
        <form method="POST" action="{{ route('support.subscriptions.plans') }}">
            @csrf @method('PUT')
            @foreach ($plans as $plan)
                <div class="plan-row">
                    <strong>{{ $plan->label() }}</strong>
                    <div class="field">
                        <label for="plan_{{ $plan->id }}_price">{{ __('Prix') }}</label>
                        <input id="plan_{{ $plan->id }}_price" type="number" name="plans[{{ $plan->id }}][price]" step="0.01" min="0.01" value="{{ old("plans.{$plan->id}.price", $plan->price === null ? null : (float) $plan->price) }}">
                        @error("plans.{$plan->id}.price")<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="plan_{{ $plan->id }}_currency">{{ __('Devise') }}</label>
                        <select id="plan_{{ $plan->id }}_currency" name="plans[{{ $plan->id }}][currency]">
                            @foreach ($currencies as $code => [$label])
                                <option value="{{ $code }}" @selected(old("plans.{$plan->id}.currency", $plan->currency) === $code)>{{ $code }} — {{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="check">
                        <input type="hidden" name="plans[{{ $plan->id }}][is_active]" value="0">
                        <input type="checkbox" name="plans[{{ $plan->id }}][is_active]" value="1" @checked(old("plans.{$plan->id}.is_active", $plan->is_active))> {{ __('Actif') }}
                    </label>
                </div>
            @endforeach
            <div class="form-actions"><button type="submit" class="btn">{{ __('Enregistrer les plans') }}</button></div>
        </form>
    </div>

    <div class="card">
        <h2>{{ __('Modes de paiement') }}</h2>
        <p class="soft small">{{ __('Orange Money et MTN sont affichés comme indisponibles jusqu\'au branchement de leurs API : seules les espèces sont acceptées.') }}</p>
        <form method="POST" action="{{ route('support.subscriptions.methods') }}">
            @csrf @method('PUT')
            <x-field name="trial_days" :label="__('Durée de l\'essai gratuit (jours)')" type="number" min="0" max="365" step="1" :value="$trialDays" :required="true" :help="__('S\'applique aux prochains comptes créés.')" />
            <x-field name="cash_instructions" :label="__('Consignes pour un paiement en espèces')">
                <textarea id="cash_instructions" name="cash_instructions" rows="2" maxlength="500">{{ old('cash_instructions', $cashInstructions) }}</textarea>
            </x-field>
            <div class="form-actions"><button type="submit" class="btn">{{ __('Enregistrer') }}</button></div>
        </form>
    </div>
@endsection
