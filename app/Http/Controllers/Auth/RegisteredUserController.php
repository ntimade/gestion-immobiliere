<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PasswordRecovery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register', ['currencies' => config('currencies')]);
    }

    public function store(Request $request, PasswordRecovery $recovery): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'organisation_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_country' => ['required', Rule::in(array_keys(config('phone_countries')))],
            'address' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', Rule::in(array_keys(config('currencies')))],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ] + $recovery->rules(), $recovery->messages(), ['phone_country' => __('pays')] + $recovery->attributes());

        $user = $this->createAccount($data, $recovery);

        Auth::login($user);
        $request->session()->regenerate();

        // Le mot de passe n'est conservé, chiffré, que le temps de générer le fichier.
        $request->session()->put(WelcomeController::SESSION_KEY, [
            'password' => Crypt::encryptString($data['password']),
            'expires_at' => now()->addMinutes(WelcomeController::TTL_MINUTES)->timestamp,
        ]);

        return redirect()->route('welcome');
    }

    private function createAccount(array $data, PasswordRecovery $recovery): User
    {
        // Deux inscriptions simultanées peuvent viser le même code : on en recalcule un.
        return retry(3, fn () => DB::transaction(function () use ($data, $recovery) {
            $organisation = Organisation::create([
                'name' => $data['organisation_name'],
                'code' => Organisation::generateCode($data['organisation_name']),
                'phone' => $data['phone'] ?? null,
                'phone_country' => $data['phone_country'],
                'address' => $data['address'] ?? null,
                'currency' => $data['currency'],
            ]);

            $organisation->forceFill(['trial_ends_at' => now()->addDays((int) (PlatformSetting::get('trial_days') ?? config('subscription.trial_days')))->toDateString()])->save();

            $user = $organisation->users()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $recovery->saveAnswers($user, $data['security']);

            return $user;
        }), 100, fn ($e) => $e instanceof UniqueConstraintViolationException);
    }
}
