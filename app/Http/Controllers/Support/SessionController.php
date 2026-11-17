<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('support.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($data['email']));
        $key = 'support-login|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)]),
            ]);
        }

        if (! Auth::guard('support')->attempt(['email' => $email, 'password' => $data['password']])) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route('support.requests.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('support')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('support.login');
    }
}
