<?php

namespace App\Http\Middleware;

use App\Services\RentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Crée les factures du jour à la première visite : aucune tâche planifiée à installer sur le serveur.
class SyncRentInvoices
{
    public function __construct(private RentService $rent)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $organisation = $request->user()?->organisation;

        if ($organisation && ! $request->is('support', 'support/*') && ! $organisation->isAccessLocked()
            && $organisation->invoices_synced_on?->toDateString() !== today()->toDateString()) {
            $this->rent->syncOrganisation($organisation);
        }

        return $next($request);
    }
}
