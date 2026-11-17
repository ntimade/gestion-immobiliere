<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureSubscriptionChosen;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SyncRentInvoices;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Lue avant le déchiffrement des cookies : elle ne contient qu'une valeur de la liste autorisée.
        $middleware->encryptCookies(except: ['locale', 'theme']);
        $middleware->append(SetLocale::class);
        $middleware->web(append: [EnsureAccountIsActive::class, ForcePasswordChange::class, EnsureSubscriptionChosen::class, SyncRentInvoices::class]);
        $middleware->redirectUsersTo(fn (Request $request) => route($request->is('support', 'support/*') ? 'support.requests.index' : 'dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Formulaire expiré malgré le rafraîchissement du jeton (JavaScript désactivé, par
        // exemple) : retour au formulaire avec sa saisie, sauf les mots de passe.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 419 || $request->expectsJson()) {
                return $response;
            }

            return back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                ->with('error', __('La page avait expiré et rien n\'a été enregistré. Vérifiez les informations et renvoyez le formulaire.'));
        });
    })->create();
