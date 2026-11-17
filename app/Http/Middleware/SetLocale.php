<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// La langue choisie reste sur l'appareil, y compris sur les pages de connexion et d'erreur.
class SetLocale
{
    public const LOCALES = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->cookie('locale'), self::LOCALES, true)) {
            app()->setLocale($request->cookie('locale'));
        }

        return $next($request);
    }
}
