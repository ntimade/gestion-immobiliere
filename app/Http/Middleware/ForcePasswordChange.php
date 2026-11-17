<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Après une réinitialisation par le support, le mot de passe temporaire doit être remplacé.
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('settings.edit', 'settings.password', 'logout', 'locale.switch', 'theme.switch', 'csrf.refresh')
            && ! $request->is('support', 'support/*')) {
            return redirect()->to(route('settings.edit').'#section-mot-de-passe')
                ->with('error', __('Choisissez un nouveau mot de passe pour continuer.'));
        }

        return $next($request);
    }
}
