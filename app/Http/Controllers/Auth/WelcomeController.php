<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

class WelcomeController extends Controller
{
    public const SESSION_KEY = 'registration_credentials';

    public const TTL_MINUTES = 15;

    public function show(Request $request): View|RedirectResponse
    {
        if (! $this->password($request)) {
            return redirect()->route('dashboard');
        }

        return view('auth.welcome', ['user' => $request->user()]);
    }

    public function download(Request $request): Response|RedirectResponse
    {
        $password = $this->password($request);

        if (! $password) {
            return redirect()->route('dashboard')
                ->with('error', __('Ce fichier n\'est plus disponible. Si besoin, utilisez « Mot de passe oublié ».'));
        }

        $user = $request->user();
        $organisation = $user->organisation;

        $content = implode("\r\n", [
            config('app.name').__(' - Identifiants de connexion'),
            str_repeat('=', 50),
            '',
            __('Organisation      : ').$organisation->name,
            __('Code organisation : ').$organisation->code,
            __('Login             : ').$user->email,
            __('Mot de passe      : ').$password,
            '',
            __('Adresse de connexion : ').route('login'),
            __('Fichier créé le      : ').now()->format('d/m/Y H:i'),
            '',
            __('Conservez ce fichier en lieu sûr et ne le partagez pas.'),
            __('Il ne sera plus possible de le télécharger plus tard.'),
            '',
        ]);

        return response("\xEF\xBB\xBF".$content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="identifiants-'.$organisation->code.'.txt"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function finish(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('dashboard');
    }

    private function password(Request $request): ?string
    {
        $credentials = $request->session()->get(self::SESSION_KEY);

        if (! $credentials) {
            return null;
        }

        if ($credentials['expires_at'] < now()->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return Crypt::decryptString($credentials['password']);
    }
}
