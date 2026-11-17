<?php

use App\Models\SupportAdmin;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Crée un compte du poste de support, ou change son mot de passe. Aucun n'existe par défaut.
Artisan::command('support:admin {email} {--name=Support}', function (string $email) {
    $password = (string) $this->secret('Mot de passe');
    $confirmation = (string) $this->secret('Confirmer le mot de passe');

    if ($password !== $confirmation || mb_strlen($password) < 12 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('E-mail invalide, ou mot de passe trop court (12 caractères au moins) ou différent de sa confirmation.');

        return 1;
    }

    SupportAdmin::updateOrCreate(['email' => mb_strtolower($email)], ['name' => $this->option('name'), 'password' => $password]);

    $this->info('Compte de support enregistré.');

    return 0;
})->purpose('Créer un compte du poste de support');
