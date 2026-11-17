<?php

namespace Database\Seeders;

use App\Models\SupportAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Crée le compte super admin, qui se connecte sur /support/connexion sans code organisation.
 * Le mot de passe est généré ici et affiché une seule fois : aucun secret n'est écrit dans le code.
 * Relancé, ce seeder ne change rien à un compte existant.
 */
class SupportAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = Str::lower(env('SUPPORT_ADMIN_EMAIL', 'superadmin@gestion-immobiliere.local'));

        if (SupportAdmin::where('email', $email)->exists()) {
            $this->command?->info("Le compte super admin {$email} existe déjà : rien n'a changé.");

            return;
        }

        $password = Str::password(16, symbols: false);

        SupportAdmin::create(['name' => 'Super admin', 'email' => $email, 'password' => $password]);

        $this->command?->info('Compte super admin créé. Notez ce mot de passe, il ne sera plus affiché :');
        $this->command?->line("E-mail : {$email}");
        $this->command?->line("Mot de passe : {$password}");
    }
}
