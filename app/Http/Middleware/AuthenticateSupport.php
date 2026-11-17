<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remplace auth:support, qui ferait du support la garde par défaut : la session serait alors
 * enregistrée au nom de l'identifiant support et pourrait bloquer le propriétaire de même identifiant.
 */
class AuthenticateSupport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('support')->check()) {
            return redirect()->route('support.login');
        }

        return $next($request);
    }
}
