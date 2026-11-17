<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    // Un compte peut être ouvert sur deux appareils à la fois (téléphone et ordinateur, par exemple) ; le troisième est refusé.
    public const MAX_DEVICES = 2;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Deux appareils au plus par compte : refuser si ce compte a déjà deux sessions
        // actives ailleurs. La déconnexion ou la réinitialisation du mot de passe en libère une.
        $otherActiveSessions = DB::table('sessions')
            ->where('user_id', Auth::id())
            ->where('id', '!=', $request->session()->getId())
            ->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)
            ->count();

        if ($otherActiveSessions >= self::MAX_DEVICES) {
            Auth::logout();

            return back()->withErrors([
                'email' => __('Ce compte est déjà connecté sur :v1 appareils. Déconnectez-vous de l\'un d\'eux pour continuer.', ['v1' => self::MAX_DEVICES]),
            ])->onlyInput('email', 'organisation_code');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
