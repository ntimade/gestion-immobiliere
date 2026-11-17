@extends('layouts.support')
@section('title', $account->name)

@section('content')
    <div class="page-head">
        <h1>{{ $account->name }} <span class="badge {{ $account->isSuspended() ? 'ko' : 'ok' }}">{{ $account->isSuspended() ? __('Suspendu') : __('Actif') }}</span></h1>
        <a class="btn ghost" href="{{ route('support.accounts.index') }}">{{ __('Retour') }}</a>
    </div>

    @if (session('temporary_password'))
        <div class="card temp-password">
            <h2>{{ __('Mot de passe temporaire') }}</h2>
            <p class="small">{{ __('Communiquez-le au propriétaire du compte. Il ne sera plus affiché après cette page, et il devra être changé à la première connexion.') }}</p>
            <div class="credentials">
                <div><span class="soft">{{ __('Code organisation') }}</span><strong>{{ $account->code }}</strong></div>
                <div><span class="soft">{{ __('Login') }}</span><strong>{{ session('temporary_login') }}</strong></div>
                <div><span class="soft">{{ __('Mot de passe') }}</span><strong>{{ session('temporary_password') }}</strong></div>
            </div>
        </div>
    @endif

    @if ($account->isSuspended())
        <div class="flash error">{{ __('Suspendu le :v1. Motif : :v2', ['v1' => $account->suspended_at->format('d/m/Y H:i'), 'v2' => $account->suspension_reason]) }}</div>
    @endif

    <div class="card">
        <h2>{{ __('Organisation') }}</h2>
        <dl class="detail">
            <div><dt>{{ __('Code organisation') }}</dt><dd>{{ $account->code }}</dd></div>
            <div><dt>{{ __('Téléphone') }}</dt><dd>{{ $account->phone ?: '—' }}</dd></div>
            <div><dt>{{ __('Adresse') }}</dt><dd>{{ $account->address ?: '—' }}</dd></div>
            <div><dt>{{ __('Devise') }}</dt><dd>{{ $account->currency }}</dd></div>
            <div><dt>{{ __('Créé le') }}</dt><dd>{{ $account->created_at?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt>{{ __('Locataires') }}</dt><dd>{{ $account->tenants_total }}</dd></div>
            <div><dt>{{ __('Logements') }}</dt><dd>{{ $account->units_total }}</dd></div>
            <div><dt>{{ __('Factures') }}</dt><dd>{{ $account->invoices_total }}</dd></div>
            <div><dt>{{ __('Impayés') }}</dt><dd>{{ number_format((float) $account->unpaid_total, $account->currencyDecimals(), ',', ' ') }} {{ $account->currency }}</dd></div>
        </dl>
    </div>

    <div class="card">
        <h2>{{ __('Utilisateurs') }}</h2>
        @foreach ($users as $user)
            <div class="support-user">
                <dl class="detail">
                    <div><dt>{{ __('Nom') }}</dt><dd>{{ $user->name }}</dd></div>
                    <div><dt>{{ __('Login') }}</dt><dd>{{ $user->email }}</dd></div>
                    <div><dt>{{ __('Questions de sécurité') }}</dt><dd><span class="badge {{ $user->security_answers_count ? 'ok' : 'partial' }}">{{ $user->security_answers_count ? __('Renseignées') : __('Non renseignées') }}</span></dd></div>
                    <div><dt>{{ __('Mot de passe') }}</dt><dd><span class="badge {{ $user->must_change_password ? 'partial' : 'ok' }}">{{ $user->must_change_password ? __('Temporaire') : __('Personnel') }}</span></dd></div>
                </dl>
                <form method="POST" action="{{ route('support.accounts.reset', [$account->id, $user->id]) }}" data-confirm="{{ __('Réinitialiser ce mot de passe ? Le compte sera déconnecté.') }}" onsubmit="return confirm(this.dataset.confirm)">
                    @csrf
                    <div class="field">
                        <label class="check"><input type="checkbox" name="identity_verified" value="1" required> {{ __('J\'ai vérifié l\'identité du propriétaire') }}</label>
                        @error('identity_verified')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn">{{ __('Générer un mot de passe temporaire') }}</button>
                </form>
            </div>
        @endforeach
    </div>

    <div class="card">
        <h2>{{ __('Prolonger l\'abonnement') }}</h2>
        @include('subscription.status', ['organisation' => $account])
        <form method="POST" action="{{ route('support.subscriptions.extend', $account->id) }}" data-confirm="{{ __('Prolonger l\'abonnement de ce compte ?') }}" onsubmit="return confirm(this.dataset.confirm)">
            @csrf
            <div class="form-grid">
                <x-field name="months" :label="__('Nombre de mois')" type="number" min="1" max="36" step="1" :value="1" :required="true" />
                <x-field name="method" :label="__('Mode de paiement')" :required="true">
                    <select id="method" name="method" required>
                        @foreach (\App\Models\SubscriptionPayment::METHODS as $method)
                            <option value="{{ $method }}" @selected(old('method', 'cash') === $method)>{{ \App\Models\SubscriptionPayment::labelForMethod($method) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field name="amount" :label="__('Montant reçu')" type="number" step="0.01" min="0.01" />
                <x-field name="currency" :label="__('Devise')">
                    <select id="currency" name="currency">
                        @foreach (config('currencies') as $code => [$label])
                            <option value="{{ $code }}" @selected(old('currency', 'XAF') === $code)>{{ $code }} — {{ __($label) }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <x-field name="reference" :label="__('Référence de la transaction')" />
            <button type="submit" class="btn">{{ __('Prolonger') }}</button>
        </form>
    </div>

    <div class="card">
        <h2>{{ __('Historique des paiements') }}</h2>
        @if ($payments->isEmpty())
            <p class="empty">{{ __('Aucun paiement.') }}</p>
        @else
            <div class="table-wrap"><table id="t-paiements">
                <tr>
                    <x-sort-th :table="$payments" key="date" kind="date">{{ __('Date') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="months" kind="number">{{ __('Plan') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="amount" kind="number" class="right">{{ __('Montant') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="method">{{ __('Mode') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="end" kind="date">{{ __('Échéance') }}</x-sort-th>
                    <x-sort-th :table="$payments" key="status">{{ __('État') }}</x-sort-th>
                    <th></th>
                </tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment->created_at->format('d/m/Y') }}</td>
                        <td>{{ \App\Models\SubscriptionPlan::labelFor($payment->months) }}</td>
                        <td class="right">{{ $payment->amountLabel() }}</td>
                        <td>{{ $payment->methodLabel() }}</td>
                        <td>{{ $payment->period_end?->format('d/m/Y') ?? '—' }}</td>
                        <td><span class="badge {{ $payment->statusBadge() }}">{{ $payment->statusLabel() }}</span></td>
                        <td>@include('support.subscriptions.actions', ['payment' => $payment])</td>
                    </tr>
                @endforeach
            </table></div>
            {{ $payments->links() }}
        @endif
    </div>

    <div class="card">
        @if ($account->isSuspended())
            <h2>{{ __('Réactiver le compte') }}</h2>
            <p class="soft small">{{ __('Ses utilisateurs pourront de nouveau se connecter, avec les mêmes données.') }}</p>
            <form method="POST" action="{{ route('support.accounts.reactivate', $account->id) }}">
                @csrf
                <button type="submit" class="btn">{{ __('Réactiver') }}</button>
            </form>
        @else
            <h2>{{ __('Suspendre le compte') }}</h2>
            <p class="soft small">{{ __('Ses utilisateurs sont déconnectés et ne peuvent plus se connecter. Aucune donnée n\'est effacée.') }}</p>
            <form method="POST" action="{{ route('support.accounts.suspend', $account->id) }}" data-confirm="{{ __('Suspendre ce compte ? Ses utilisateurs seront déconnectés.') }}" onsubmit="return confirm(this.dataset.confirm)">
                @csrf
                <x-field name="reason" :label="__('Motif')" :required="true" maxlength="500" />
                <button type="submit" class="btn danger">{{ __('Suspendre') }}</button>
            </form>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Historique du support') }}</h2>
        @if ($actions->isEmpty() && $requests->isEmpty())
            <p class="empty">{{ __('Aucune action pour ce compte.') }}</p>
        @else
            @if ($actions->isNotEmpty())
                <div class="table-wrap"><table>
                    <tr><th>{{ __('Date') }}</th><th>{{ __('Action') }}</th><th>{{ __('Par') }}</th><th>{{ __('Observation') }}</th></tr>
                    @foreach ($actions as $action)
                        <tr>
                            <td>{{ $action->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $action->label() }}</td>
                            <td>{{ $action->admin?->name ?? '—' }}</td>
                            <td class="wrap">{{ $action->note ?: '—' }}</td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
            @if ($requests->isNotEmpty())
                <p class="support-requests small">
                    {{ __('Dernières demandes :') }}
                    @foreach ($requests as $item)
                        <a href="{{ route('support.requests.show', $item) }}">{{ $item->created_at->format('d/m/Y') }}</a> ({{ $item->statusLabel() }})@unless ($loop->last), @endunless
                    @endforeach
                </p>
            @endif
        @endif
    </div>
@endsection
