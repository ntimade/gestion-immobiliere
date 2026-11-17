<?php

namespace Database\Seeders;

use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\SupportAdmin;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Données d'essai de la société SIC, pour tester les factures, WhatsApp et SMS en local :
 * php artisan db:seed --class=SicTestSeeder
 *
 * Code du compte : SXX-001
 * Administrateur d'essai : e-mail admin.test@sic.local, mot de passe sic-admin-test-1
 * Gestionnaire d'essai : e-mail gestion.test@sic.local, mot de passe sic-gestion-test-1
 * Super admin d'essai, sur /support/connexion, sans code : e-mail superadmin.test@gestion-immobiliere.local, mot de passe super-admin-test-1
 * Ne pas lancer en production : ces mots de passe sont écrits ici.
 *
 * Les locataires reçoivent le numéro de la société : les messages d'essai reviennent au propriétaire,
 * jamais à un inconnu.
 */
class SicTestSeeder extends Seeder
{
    private const CODE = 'SXX-001';

    private const PROPERTY = 'Immeuble Test Bonanjo';

    public function run(RentService $rent): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Données d\'essai refusées en production.');

            return;
        }

        $organisation = Organisation::where('code', self::CODE)->first();

        if (! $organisation || ! $organisation->phone) {
            $this->command?->error('Société SIC ou numéro de téléphone introuvable.');

            return;
        }

        // Pour essayer la notification judiciaire : les impayés d'octobre atteignent ce délai en novembre.
        $organisation->update(['legal_notice_after' => 1, 'legal_notice_unit' => 'months']);

        foreach ([
            ['Admin Test SIC', 'admin.test@sic.local', 'sic-admin-test-1', User::ADMIN],
            ['Gestionnaire Test SIC', 'gestion.test@sic.local', 'sic-gestion-test-1', User::MANAGER],
        ] as [$name, $email, $password, $role]) {
            if (! User::where('email', $email)->exists()) {
                $user = $organisation->users()->create(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->forceFill(['role' => $role])->save();
            }
        }

        SupportAdmin::firstOrCreate(['email' => 'superadmin.test@gestion-immobiliere.local'], ['name' => 'Super admin test', 'password' => 'super-admin-test-1']);

        Auth::setUser($organisation->users()->orderBy('id')->firstOrFail());

        $this->firstOfMonthTenants($rent, $organisation);

        if (Property::where('name', self::PROPERTY)->exists()) {
            $this->command?->info('Les données d\'essai de SIC existent déjà.');

            return;
        }

        $phone = \App\Support\PhoneNumber::forStorage($organisation->phone, $organisation->phone_country);
        $property = Property::create(['name' => self::PROPERTY, 'city' => 'Douala', 'neighbourhood' => 'Bonanjo', 'address' => 'Essai', 'latitude' => 4.0435, 'longitude' => 9.6866]);
        $water = ['water', 'electricity'];

        $unit = fn (string $number, string $type, int $price, array $details) => $property->units()->create(['number' => $number, 'type' => $type, 'rent' => $price, 'amenities' => $water] + $details);

        // Échéance dans 2 jours, mois précédents payés : apparaît dans les rappels.
        $this->enter($rent, $unit('A1', 'apartment', 180000, ['bedrooms' => 3, 'living_rooms' => 1, 'kitchens' => 1, 'bathrooms' => 2, 'surface' => 110, 'floor' => '1er']),
            'Test Awa', 'F', $phone, now()->addDays(2)->subMonthsNoOverflow(2), paidMonths: 2);

        // Deux mois échus : apparaît dans les relances.
        $this->enter($rent, $unit('A2', 'apartment', 130000, ['bedrooms' => 2, 'living_rooms' => 1, 'kitchens' => 1, 'bathrooms' => 1, 'surface' => 75, 'floor' => '2e']),
            'Test Paul', 'M', $phone, now()->subDays(5)->subMonthNoOverflow());

        // Six mois d'avance : confirmation de paiement à envoyer, aucun rappel.
        $advance = $this->enter($rent, $unit('S1', 'studio', 70000, ['bedrooms' => 1, 'kitchens' => 1, 'bathrooms' => 1, 'surface' => 32, 'floor' => '2e', 'furnished' => true]),
            'Test Jean', 'M', $phone, now()->subDays(20));
        $rent->recordPayment($advance, 420000, now()->subDay()->toDateString(), 'Mobile Money', 'MM-TEST-1');

        // Payé en partie, échéance dans 10 jours : facture à envoyer.
        $partial = $this->enter($rent, $unit('C1', 'room', 35000, ['bedrooms' => 1, 'bathrooms' => 1, 'surface' => 14]),
            'Test Marie', 'F', $phone, now()->addDays(10)->subMonthNoOverflow());
        $rent->recordPayment($partial, 10000, now()->toDateString(), 'Espèces', null);

        // Sans numéro : signalé dans les rappels.
        $this->enter($rent, $unit('C2', 'room', 35000, ['bedrooms' => 1, 'surface' => 12]),
            'Test Sans Numero', 'M', null, now()->addDay()->subMonthNoOverflow(), paidMonths: 1);

        $unit('C3', 'room', 35000, ['bedrooms' => 1, 'surface' => 12]);

        // Un bien qui est un logement seul, libre.
        $single = Property::create(['name' => 'Studio Test Akwa', 'kind' => 'studio', 'city' => 'Douala', 'neighbourhood' => 'Akwa', 'latitude' => 4.0483, 'longitude' => 9.7043]);
        $single->units()->create(['number' => '1', 'type' => 'studio', 'rent' => 75000, 'surface' => 28, 'bedrooms' => 1, 'kitchens' => 1, 'bathrooms' => 1, 'furnished' => true, 'amenities' => $water]);

        $rent->syncOrganisation($organisation);
    }

    /**
     * Locataires dont le loyer tombe le 1er du mois, entrés le mois dernier ou ce mois-ci :
     * certains à jour, d'autres avec le mois en cours ou le mois dernier encore à payer.
     */
    private function firstOfMonthTenants(RentService $rent, Organisation $organisation): void
    {
        $name = 'Résidence Test Echeance 1er';

        if (Property::where('name', $name)->exists()) {
            return;
        }

        $phone = \App\Support\PhoneNumber::forStorage($organisation->phone, $organisation->phone_country);
        $property = Property::create(['name' => $name, 'city' => 'Douala', 'neighbourhood' => 'Deido', 'latitude' => 4.0669, 'longitude' => 9.7118]);
        $thisMonth = now()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonthNoOverflow();

        // [logement, type, loyer, chambres, locataire, sexe, entrée, paiements : [montant, date]]
        $rows = [
            ['D1', 'apartment', 120000, 2, 'Test Pierre (tout paye)', 'M', $lastMonth, [[120000, $lastMonth], [120000, $thisMonth]]],
            ['D2', 'apartment', 120000, 2, 'Test Nadine (mois en cours du)', 'F', $lastMonth, [[120000, $lastMonth]]],
            ['D3', 'apartment', 150000, 3, 'Test Eric (2 mois dus)', 'M', $lastMonth, []],
            ['D4', 'studio', 70000, 1, 'Test Claire (mois en cours en partie)', 'F', $lastMonth, [[70000, $lastMonth], [30000, $thisMonth]]],
            ['D5', 'studio', 70000, 1, 'Test Luc (mois dernier en partie)', 'M', $lastMonth, [[40000, $lastMonth]]],
            ['D6', 'room', 35000, 1, 'Test Sonia (entree ce mois, payee)', 'F', $thisMonth, [[35000, $thisMonth]]],
            ['D7', 'room', 35000, 1, 'Test Marc (entree ce mois, du)', 'M', $thisMonth, []],
        ];

        foreach ($rows as [$number, $type, $price, $bedrooms, $tenant, $gender, $start, $payments]) {
            $unit = $property->units()->create(['number' => $number, 'type' => $type, 'rent' => $price, 'bedrooms' => $bedrooms, 'amenities' => ['water', 'electricity']]);
            $lease = $rent->startLease($unit, Tenant::create(['name' => $tenant, 'gender' => $gender, 'phone' => $phone]), [
                'start_date' => $start->toDateString(),
                'due_day' => 1,
                'rent' => $price,
                'deposit_amount' => $price,
                'deposit_paid_at' => $start->toDateString(),
            ]);

            foreach ($payments as [$amount, $date]) {
                $rent->recordPayment($lease, $amount, $date->toDateString(), 'Espèces', null);
            }
        }
    }

    private function enter(RentService $rent, Unit $unit, string $name, string $gender, ?string $phone, Carbon $start, int $paidMonths = 0): Lease
    {
        $lease = $rent->startLease($unit, Tenant::create(['name' => $name, 'gender' => $gender, 'phone' => $phone]), [
            'start_date' => $start->toDateString(),
            'due_day' => $start->day,
            'rent' => $unit->rent,
            'deposit_amount' => (float) $unit->rent * 2,
            'deposit_paid_at' => $start->toDateString(),
        ]);

        if ($paidMonths > 0) {
            $rent->recordPayment($lease, $paidMonths * (float) $unit->rent, now()->subDays(3)->toDateString(), 'Espèces', null);
        }

        return $lease;
    }
}
