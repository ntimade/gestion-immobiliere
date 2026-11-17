@extends('layouts.support')
@section('title', __('Demande'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Demande du :v1', ['v1' => $item->created_at->format('d/m/Y H:i')]) }} <span class="badge {{ $item->statusBadge() }}">{{ $item->statusLabel() }}</span></h1>
        <a class="btn ghost" href="{{ route('support.requests.index') }}">{{ __('Retour') }}</a>
    </div>

    @if (session('temporary_password'))
        <div class="card temp-password">
            <h2>{{ __('Mot de passe temporaire') }}</h2>
            <p class="small">{{ __('Communiquez-le au propriétaire du compte. Il ne sera plus affiché après cette page, et il devra être changé à la première connexion.') }}</p>
            <div class="credentials">
                <div><span class="soft">{{ __('Code organisation') }}</span><strong>{{ $item->organisation_code }}</strong></div>
                <div><span class="soft">{{ __('Login') }}</span><strong>{{ $item->login }}</strong></div>
                <div><span class="soft">{{ __('Mot de passe') }}</span><strong>{{ session('temporary_password') }}</strong></div>
            </div>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('La demande') }}</h2>
        <dl class="detail">
            <div><dt>{{ __('Code organisation') }}</dt><dd>{{ $item->organisation_code }}</dd></div>
            <div><dt>{{ __('Login') }}</dt><dd>{{ $item->login }}</dd></div>
            <div><dt>{{ __('Où joindre') }}</dt><dd>{{ $item->contact }}</dd></div>
            <div><dt>{{ __('Message') }}</dt><dd>{{ $item->message ?: '—' }}</dd></div>
        </dl>
    </div>

    <div class="card">
        <h2>{{ __('Le compte') }}</h2>
        @if ($item->user)
            <dl class="detail">
                <div><dt>{{ __('Organisation') }}</dt><dd>{{ $item->user->organisation->name }}</dd></div>
                <div><dt>{{ __('Propriétaire') }}</dt><dd>{{ $item->user->name }}</dd></div>
                <div><dt>{{ __('Téléphone') }}</dt><dd>{{ $item->user->organisation->phone ?: '—' }}</dd></div>
                <div><dt>{{ __('Adresse') }}</dt><dd>{{ $item->user->organisation->address ?: '—' }}</dd></div>
            </dl>
        @else
            <p class="flash error">{{ __('Aucun compte ne correspond à ce code et à ce login. Vérifiez-les avec le demandeur, qui devra refaire une demande.') }}</p>
        @endif
    </div>

    @if ($item->isPending())
        @if ($item->user)
            <div class="card">
                <h2>{{ __('Réinitialiser le mot de passe') }}</h2>
                <p class="small soft">{{ __('Vérifiez d\'abord que la personne est bien le propriétaire, par exemple en la rappelant au numéro de l\'organisation. Sinon, n\'importe qui pourrait prendre le compte.') }}</p>
                <form method="POST" action="{{ route('support.requests.reset', $item) }}" data-confirm="{{ __('Réinitialiser ce mot de passe ? Le compte sera déconnecté.') }}" onsubmit="return confirm(this.dataset.confirm)">
                    @csrf
                    <div class="field">
                        <label class="check"><input type="checkbox" name="identity_verified" value="1" required> {{ __('J\'ai vérifié l\'identité du propriétaire') }}</label>
                        @error('identity_verified')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn">{{ __('Générer un mot de passe temporaire') }}</button>
                </form>
            </div>
        @endif

        <div class="card">
            <h2>{{ __('Clôturer sans réinitialiser') }}</h2>
            <form method="POST" action="{{ route('support.requests.close', $item) }}">
                @csrf
                <x-field name="note" :label="__('Observation')">
                    <textarea id="note" name="note" rows="2" maxlength="1000">{{ old('note') }}</textarea>
                </x-field>
                <button type="submit" class="btn danger">{{ __('Clôturer') }}</button>
            </form>
        </div>
    @else
        <div class="card">
            <h2>{{ __('Traitement') }}</h2>
            <dl class="detail">
                <div><dt>{{ __('Par') }}</dt><dd>{{ $item->handler?->name ?? '—' }}</dd></div>
                <div><dt>{{ __('Le') }}</dt><dd>{{ $item->handled_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                <div><dt>{{ __('Observation') }}</dt><dd>{{ $item->note ?: '—' }}</dd></div>
            </dl>
        </div>
    @endif
@endsection
