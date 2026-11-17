<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\PasswordSupportRequest;
use App\Services\PasswordRecovery;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RequestController extends Controller
{
    public function index(Request $request): View
    {
        $all = $request->query('etat') === 'toutes';

        return view('support.requests.index', [
            'all' => $all,
            'pendingCount' => PasswordSupportRequest::where('status', PasswordSupportRequest::PENDING)->count(),
            'requests' => SortedTable::query(
                PasswordSupportRequest::with('user.organisation')
                    ->when(! $all, fn ($q) => $q->where('status', PasswordSupportRequest::PENDING))
                    ->latest(),
                'demandes',
                ['date' => 'created_at', 'code' => 'organisation_code', 'login' => 'login', 'contact' => 'contact', 'status' => 'status'],
            ),
        ]);
    }

    public function show(PasswordSupportRequest $supportRequest): View
    {
        return view('support.requests.show', ['item' => $supportRequest->load(['user.organisation', 'handler'])]);
    }

    public function reset(Request $request, PasswordSupportRequest $supportRequest, PasswordRecovery $recovery): RedirectResponse
    {
        $request->validate(['identity_verified' => ['accepted']], [
            'identity_verified.accepted' => __('Confirmez d\'abord que vous avez vérifié l\'identité du propriétaire.'),
        ]);

        if (! $supportRequest->isPending() || ! $supportRequest->user) {
            return back()->with('error', __('Cette demande ne permet pas de réinitialiser un mot de passe.'));
        }

        // Mot de passe temporaire : affiché une seule fois, à changer dès la première connexion.
        $temporary = Str::password(12, symbols: false);

        $recovery->setPassword($supportRequest->user, $temporary, mustChange: true);

        $supportRequest->update([
            'status' => PasswordSupportRequest::RESET,
            'handled_by' => $request->user('support')->id,
            'handled_at' => now(),
        ]);

        return redirect()->route('support.requests.show', $supportRequest)->with('temporary_password', $temporary);
    }

    public function close(Request $request, PasswordSupportRequest $supportRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        if ($supportRequest->isPending()) {
            $supportRequest->update([
                'status' => PasswordSupportRequest::CLOSED,
                'note' => $data['note'] ?? null,
                'handled_by' => $request->user('support')->id,
                'handled_at' => now(),
            ]);
        }

        return redirect()->route('support.requests.index')->with('success', __('Demande clôturée.'));
    }
}
