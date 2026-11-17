<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Abonnement expiré ou essai terminé : seule la page Abonnement reste ouverte. Aucune donnée n'est effacée.
class EnsureSubscriptionChosen
{
    private const ALWAYS_OPEN = ['subscription.*', 'settings.logo', 'logout', 'locale.switch', 'theme.switch', 'csrf.refresh'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('support', 'support/*') || ! $request->user()?->organisation?->isAccessLocked()) {
            return $next($request);
        }

        // Un mot de passe temporaire doit d'abord être remplacé : la page de changement reste ouverte.
        $passwordToChange = $request->user()->must_change_password && $request->routeIs('settings.edit', 'settings.password');

        if ($request->routeIs(...self::ALWAYS_OPEN) || $passwordToChange) {
            return $next($request);
        }

        return redirect()->route('subscription.index');
    }
}
