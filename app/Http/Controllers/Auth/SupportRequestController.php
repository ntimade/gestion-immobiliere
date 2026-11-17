<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordSupportRequest;
use App\Services\PasswordRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportRequestController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.support-request', ['account' => $request->session()->get('password_recovery', [])]);
    }

    public function store(Request $request, PasswordRecovery $recovery): RedirectResponse
    {
        $data = $request->validate([
            'organisation_code' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'contact' => __('moyen de vous joindre'),
            'message' => __('message'),
        ]);

        $code = $recovery->normalizeCode($data['organisation_code']);
        $login = $recovery->normalizeLogin($data['email']);
        $user = $recovery->findUser($code, $login);

        // Une demande déjà en attente est mise à jour : le support n'en voit qu'une par compte.
        PasswordSupportRequest::updateOrCreate(
            ['organisation_code' => $code, 'login' => $login, 'status' => PasswordSupportRequest::PENDING],
            ['user_id' => $user?->id, 'contact' => $data['contact'], 'message' => $data['message'] ?? null],
        );

        $request->session()->forget('password_recovery');

        // Même réponse dans tous les cas : ce formulaire ne dit pas quels comptes existent.
        return redirect()->route('login')->with('status', __('Votre demande est enregistrée. Le support vous contactera pour vérifier votre identité.'));
    }
}
