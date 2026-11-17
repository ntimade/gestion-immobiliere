<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Refuse l'ouverture de WhatsApp ou des SMS quand l'envoi des messages est désactivé (config/messaging.php).
class EnsureMessagesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('messaging.enabled')) {
            return back()->with('error', __('L\'envoi des messages WhatsApp et SMS est désactivé.'));
        }

        return $next($request);
    }
}
