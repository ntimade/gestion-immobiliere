<?php

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\RentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Compte de démonstration pour essayer l'application en local :
 * php artisan db:seed --class=DemoSeeder
 *
 * Connexion : code DEMO-001, e-mail demo@gestion-immobiliere.local, mot de passe demo-immobilier-1
 * Ne pas lancer en production : ce mot de passe est public.
 */
class DemoSeeder extends Seeder
{
    public function run(RentService $rent): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Compte de démonstration refusé en production.');

            return;
        }

        if (Organisation::where('code', 'DEMO-001')->exists()) {
            $this->command?->info('Le compte de démonstration existe déjà.');

            return;
        }

        $organisation = Organisation::create([
            'name' => 'Résidences Démo', 'code' => 'DEMO-001', 'phone' => '+237677000000', 'phone_country' => 'CM',
            'address' => 'Douala', 'currency' => 'XAF',
        ]);
        $organisation->forceFill(['trial_ends_at' => now()->addDays(30)->toDateString()])->save();

        Auth::setUser($organisation->users()->create([
            'name' => 'Propriétaire Démo', 'email' => 'demo@gestion-immobiliere.local', 'password' => 'demo-immobilier-1',
        ]));

        $palmiers = Property::create(['name' => 'Résidence Les Palmiers', 'city' => 'Douala', 'neighbourhood' => 'Bonapriso', 'latitude' => 4.0186, 'longitude' => 9.6951]);
        $cite = Property::create(['name' => 'Cité Verte', 'city' => 'Yaoundé', 'neighbourhood' => 'Bastos', 'latitude' => 3.8990, 'longitude' => 11.5213]);

        // Un bien qui est un logement seul, à côté des deux ensembles.
        $studio = Property::create(['name' => 'Studio Akwa', 'kind' => 'studio', 'city' => 'Douala', 'neighbourhood' => 'Akwa', 'latitude' => 4.0483, 'longitude' => 9.7043]);
        $studio->units()->create(['number' => '1', 'type' => 'studio', 'rent' => 75000, 'surface' => 28, 'floor' => '2e', 'bedrooms' => 1, 'kitchens' => 1, 'bathrooms' => 1, 'furnished' => true, 'amenities' => ['water', 'electricity', 'air_conditioning']]);

        $apartment = ['surface' => 85, 'bedrooms' => 2, 'living_rooms' => 1, 'kitchens' => 1, 'bathrooms' => 2, 'amenities' => ['water', 'electricity', 'parking', 'guard']];
        $room = ['surface' => 14, 'bedrooms' => 1, 'bathrooms' => 1, 'amenities' => ['water', 'electricity']];

        $units = [
            'a1' => Unit::create(['property_id' => $palmiers->id, 'number' => 'A1', 'type' => 'apartment', 'rent' => 150000, 'floor' => 'Rez-de-chaussée'] + $apartment),
            'a2' => Unit::create(['property_id' => $palmiers->id, 'number' => 'A2', 'type' => 'apartment', 'rent' => 150000, 'floor' => '1er'] + $apartment),
            'a3' => Unit::create(['property_id' => $palmiers->id, 'number' => 'A3', 'type' => 'studio', 'rent' => 90000, 'floor' => '2e', 'surface' => 35, 'bedrooms' => 1, 'kitchens' => 1, 'bathrooms' => 1, 'furnished' => true, 'amenities' => ['water', 'electricity', 'internet']]),
            'c1' => Unit::create(['property_id' => $cite->id, 'number' => '1', 'type' => 'room', 'rent' => 35000] + $room),
            'c2' => Unit::create(['property_id' => $cite->id, 'number' => '2', 'type' => 'room', 'rent' => 35000] + $room),
        ];

        // Échéance dans 2 jours : apparaît dans la liste des rappels.
        $this->enter($rent, $units['a1'], 'Awa Ngono', 'F', '+237699110011', now()->subMonths(2)->startOfMonth()->addDays(now()->addDays(2)->day - 1), now()->addDays(2)->day, paid: 2);

        // Payé 6 mois d'avance : aucun rappel.
        $advance = $this->enter($rent, $units['a2'], 'Jean Kamga', 'M', '+237699220022', now()->subDays(20), now()->subDays(20)->day);
        $rent->recordPayment($advance, 900000, now()->subDays(20)->toDateString(), 'Virement', 'VIR-2041');

        // Deux mois de retard : apparaît dans les relances.
        $this->enter($rent, $units['c1'], 'Paul Mbarga', 'M', '+237699330033', now()->subMonths(2)->startOfMonth(), 1);

        // Sans numéro valide : signalé dans la page des rappels.
        $this->enter($rent, $units['c2'], 'Marie Essomba', 'F', null, now()->subMonth()->startOfMonth()->addDays(now()->addDays(1)->day - 1), now()->addDays(1)->day, paid: 1);

        $rent->syncOrganisation($organisation);
    }

    private function enter(RentService $rent, Unit $unit, string $name, string $gender, ?string $phone, $start, int $dueDay, int $paid = 0)
    {
        $lease = $rent->startLease($unit, Tenant::create(['name' => $name, 'gender' => $gender, 'phone' => $phone]), [
            'start_date' => $start->toDateString(),
            'due_day' => $dueDay,
            'rent' => $unit->rent,
            'deposit_amount' => (float) $unit->rent * 2,
            'deposit_paid_at' => $start->toDateString(),
        ]);

        if ($paid > 0) {
            $rent->recordPayment($lease, $paid * (float) $unit->rent, now()->subDays(3)->toDateString(), 'Mobile Money', null);
        }

        return $lease;
    }
}
