<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// Couvre aussi la connexion par « Se souvenir de moi », qui ne repasse pas par le formulaire.
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('support', 'support/*') || ! $request->user()?->organisation?->isSuspended()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', __('Ce compte est suspendu. Contactez le support.'));
    }
}
