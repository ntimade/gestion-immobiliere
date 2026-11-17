@extends('layouts.app')
@section('title', __('Utilisateurs'))

@section('content')
    <div class="page-head">
        <h1>{{ __('Utilisateurs') }}</h1>
        <a class="btn" href="{{ route('users.create') }}">{{ __('Ajouter un utilisateur') }}</a>
    </div>

    @error('user')<div class="flash error">{{ $message }}</div>@enderror

    <div class="flash info">{{ __('Pour se connecter, chaque utilisateur saisit le code du compte :v1, son e-mail et son mot de passe.', ['v1' => $code]) }}</div>

    <div class="card">
        <div class="table-wrap cards-wide"><table>
            <tr><th>{{ __('Nom') }}</th><th>{{ __('E-mail') }}</th><th>{{ __('Rôle') }}</th><th>{{ __('Mot de passe') }}</th><th></th></tr>
            @foreach ($users as $member)
                <tr>
                    <td><strong>{{ $member->name }}</strong>@if ($member->is(auth()->user())) <span class="soft small">({{ __('vous') }})</span>@endif</td>
                    <td>{{ $member->email }}</td>
                    <td><span class="badge {{ $member->isAdmin() ? 'ok' : 'off' }}">{{ $member->roleLabel() }}</span></td>
                    <td>@if ($member->must_change_password)<span class="badge partial">{{ __('Provisoire') }}</span>@else<span class="badge ok">{{ __('Choisi') }}</span>@endif</td>
                    <td>
                        <div class="actions">
                            <a class="btn ghost small" href="{{ route('users.edit', $member->id) }}">{{ __('Modifier') }}</a>
                            @unless ($member->is(auth()->user()))
                                <form class="inline" method="POST" action="{{ route('users.destroy', $member->id) }}" data-confirm="{{ __('Supprimer cet utilisateur ? Il ne pourra plus se connecter.') }}" onsubmit="return confirm(this.dataset.confirm)">
                                    @csrf @method('DELETE')
                                    <button class="btn danger small">{{ __('Supprimer') }}</button>
                                </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @endforeach
        </table></div>
    </div>
@endsection
