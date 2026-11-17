<?php

namespace Database\Seeders;

use App\Models\SupportAdmin;
use Illuminate\Database\Seeder;

/**
 * Le compte super admin commun aux plateformes, pour assurer le support (/support/connexion).
 * Relancé, il remet le mot de passe d'origine : le compte reste toujours récupérable.
 * Lancement : php artisan db:seed --class=SupportSuperAdminSeeder --force
 */
class SupportSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        SupportAdmin::updateOrCreate(
            ['email' => 'superadmin@test.com'],
            ['name' => 'Super Admin Support', 'password' => 'password'],
        );

        $this->command?->info('Compte super admin superadmin@test.com prêt.');
    }
}
