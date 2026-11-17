<?php

namespace App\Http\Requests\Auth;

use App\Models\Organisation;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organisation_code' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Vérifie les trois paramètres ensemble. Le message d'erreur ne dit pas lequel
     * est faux, pour ne pas révéler quels codes organisation existent.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $organisation = Organisation::where('code', strtoupper(trim($this->input('organisation_code'))))->first();

        $credentials = [
            'email' => Str::lower(trim($this->input('email'))),
            'password' => $this->input('password'),
            'organisation_id' => $organisation?->id ?? 0,
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        if (Auth::user()->organisation->isSuspended()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => __('Ce compte est suspendu. Contactez le support.'),
            ]);
        }
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip());
    }
}
