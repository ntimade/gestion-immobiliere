<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordRecoveryController extends Controller
{
    private const ACCOUNT = 'password_recovery';

    private const VERIFIED = 'password_recovery_verified';

    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    private const VERIFIED_MINUTES = 10;

    public function __construct(private PasswordRecovery $recovery)
    {
    }

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organisation_code' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $request->session()->put(self::ACCOUNT, [
            'code' => $this->recovery->normalizeCode($data['organisation_code']),
            'login' => $this->recovery->normalizeLogin($data['email']),
        ]);

        return redirect()->route('password.questions');
    }

    public function questions(Request $request): View|RedirectResponse
    {
        $account = $request->session()->get(self::ACCOUNT);

        if (! $account) {
            return redirect()->route('password.request');
        }

        $user = $this->recovery->findUser($account['code'], $account['login']);

        return view('auth.security-questions', [
            'questions' => $this->recovery->questionsFor($user, $account['code'], $account['login']),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $account = $request->session()->get(self::ACCOUNT);

        if (! $account) {
            return redirect()->route('password.request');
        }

        // Compté par compte : changer d'appareil ne donne pas de nouveaux essais.
        $key = 'password-recovery|'.sha1($account['code'].'|'.$account['login']);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return redirect()->route('support.request')->with('error', __('Trop d\'essais. Contactez le support pour récupérer votre compte.'));
        }

        $answers = $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['nullable', 'string', 'max:100']])['answers'];

        $user = $this->recovery->findUser($account['code'], $account['login']);
        $questions = $this->recovery->questionsFor($user, $account['code'], $account['login']);

        if (! $this->recovery->answersMatch($user, $questions, $answers)) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            return back()->with('error', __('Ces informations ne correspondent pas. Vérifiez vos réponses ou contactez le support.'));
        }

        RateLimiter::clear($key);

        $request->session()->forget(self::ACCOUNT);
        $request->session()->regenerate();
        $request->session()->put(self::VERIFIED, [
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(self::VERIFIED_MINUTES)->timestamp,
        ]);

        return redirect()->route('password.new');
    }

    public function edit(Request $request): View|RedirectResponse
    {
        return $this->verified($request) ? view('auth.reset-password') : redirect()->route('password.request');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->verified($request);

        if (! $user) {
            return redirect()->route('password.request');
        }

        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $this->recovery->setPassword($user, $data['password'], mustChange: false);
        $this->recovery->closeRequestsSolvedByCustomer($user);

        $request->session()->forget(self::VERIFIED);
        $request->session()->regenerate();

        return redirect()->route('login')->with('status', __('Mot de passe modifié. Vous pouvez vous connecter.'));
    }

    private function verified(Request $request)
    {
        $verified = $request->session()->get(self::VERIFIED);

        if (! $verified || $verified['expires_at'] < now()->timestamp) {
            $request->session()->forget(self::VERIFIED);

            return null;
        }

        return \App\Models\User::find($verified['user_id']);
    }
}
