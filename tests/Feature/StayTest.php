<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Stay;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Location à la nuit des logements meublés : tranches de 24 h, une nuit minimum.
class StayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->owner = $this->owner('Alpha');
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(string $name): User
    {
        $organisation = Organisation::create(['name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF', 'phone_country' => 'CM']);

        return $organisation->users()->create(['name' => 'P', 'email' => strtolower($name).'@t.test', 'password' => 'secret-pass-1']);
    }

    private function unit(array $values = []): Unit
    {
        $property = Property::create(['name' => 'Palmiers', 'city' => 'Kribi', 'kind' => 'mixed']);

        return Unit::create($values + ['property_id' => $property->id, 'number' => 'M1', 'type' => 'studio', 'rent' => 150000,
            'furnished' => true, 'daily_rate' => 25000, 'amenities' => ['water', 'air_conditioning']]);
    }

    private function book(Unit $unit, array $values = [])
    {
        return $this->post(route('stays.store'), $values + [
            'unit_id' => $unit->id,
            'name' => 'Paul Client',
            'gender' => 'M',
            'phone' => '699 00 00 01',
            'check_in_at' => '2026-11-12T14:00',
            'days' => 2,
            'daily_rate' => 25000,
            'paid_amount' => 50000,
            'method' => 'Espèces',
        ]);
    }

    public function test_the_menu_shows_the_stays_section(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSee(route('stays.index'), false)->assertSee('Séjours');
        $this->get(route('stays.index'))->assertOk()->assertSee('Aucun logement ne se loue encore à la nuit');
    }

    public function test_a_daily_rate_is_kept_only_for_a_furnished_unit(): void
    {
        $unit = $this->unit();
        $base = ['property_id' => $unit->property_id, 'number' => 'M1', 'type' => 'studio', 'rent' => 150000, 'daily_rate' => 30000];

        $this->put(route('units.update', $unit), $base + ['furnished' => 1])->assertSessionHasNoErrors();
        $this->assertEquals(30000, (float) $unit->fresh()->daily_rate);
        $this->get(route('units.show', $unit))->assertSee('Nouveau séjour')->assertSee('Tarif journalier (24 h)');

        $this->put(route('units.update', $unit), $base)->assertSessionHasNoErrors();
        $this->assertNull($unit->fresh()->daily_rate);
        $this->get(route('units.show', $unit))->assertDontSee('Nouveau séjour');
    }

    // Un meublé avec un tarif journalier peut ne se louer qu'à la nuit : le loyer par mois devient facultatif.
    public function test_a_furnished_unit_with_a_daily_rate_needs_no_monthly_rent(): void
    {
        $unit = $this->unit();
        $base = ['property_id' => $unit->property_id, 'number' => 'M1', 'type' => 'studio', 'rent' => ''];

        $this->put(route('units.update', $unit), $base + ['furnished' => 1, 'daily_rate' => 30000])->assertSessionHasNoErrors();
        $this->assertEquals(0, (float) $unit->fresh()->rent);
        $this->get(route('units.show', $unit))->assertSee('Pas de location au mois (à la nuit seulement)');

        // Meublé : le tarif journalier est le champ affiché, c'est lui qui est demandé.
        $this->put(route('units.update', $unit), $base + ['furnished' => 1])
            ->assertSessionHasErrors(['daily_rate' => 'Le tarif journalier est obligatoire pour un logement meublé.'])->assertSessionDoesntHaveErrors('rent');
        // Non meublé : le loyer par mois reste obligatoire.
        $this->put(route('units.update', $unit), $base + ['daily_rate' => 30000])
            ->assertSessionHasErrors(['rent' => 'Le loyer par mois est obligatoire pour un logement non meublé.']);
        // Un meublé déjà loué au mois garde son loyer, sans tarif journalier.
        $this->put(route('units.update', $unit), ['rent' => 90000, 'furnished' => 1] + $base)->assertSessionHasNoErrors();
        $this->assertEquals(90000, (float) $unit->fresh()->rent);
        $this->assertNull($unit->fresh()->daily_rate);

        // Dans un ensemble de logements, ligne par ligne.
        $this->post(route('properties.store'), [
            'name' => 'Résidence', 'kind' => 'mixed', 'city' => 'Kribi',
            'units' => [
                ['type' => 'apartment', 'number' => 'cp01', 'rent' => '', 'bedrooms' => 2, 'furnished' => 1, 'daily_rate' => 40000],
                ['type' => 'apartment', 'number' => 'cp02', 'rent' => '', 'bedrooms' => 2],
            ],
        ])->assertSessionHasErrors('units.1.rent')->assertSessionDoesntHaveErrors('units.0.rent');

        $this->post(route('properties.store'), [
            'name' => 'Résidence', 'kind' => 'mixed', 'city' => 'Kribi',
            'units' => [['type' => 'apartment', 'number' => 'cp01', 'rent' => '', 'bedrooms' => 2, 'furnished' => 1, 'daily_rate' => 40000]],
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Unit::where('number', 'cp01')->firstOrFail()->rentsByNight());
    }

    public function test_a_stay_is_counted_in_blocks_of_24_hours(): void
    {
        $unit = $this->unit();

        $response = $this->book($unit);
        $stay = Stay::firstOrFail();
        $response->assertRedirect(route('stays.show', $stay));

        $this->assertSame('2026-11-14 14:00', $stay->check_out_at->format('Y-m-d H:i'));
        $this->assertEquals(50000, (float) $stay->amount);
        $this->assertTrue($stay->isPaid());
        $this->assertSame('+237699000001', $stay->guest->phone);
        $this->assertSame($this->owner->id, $stay->user_id);

        $this->get(route('stays.show', $stay))->assertOk()
            ->assertSee('le 14/11/2026 à 14:00')->assertSee('Envoyer sur WhatsApp')->assertSee('2 jours (48 h)');

        // Un jour = 24 h.
        $this->book($unit, ['check_in_at' => '2026-11-20T09:30', 'days' => 1, 'paid_amount' => 25000])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-21 09:30', Stay::latest('id')->first()->check_out_at->format('Y-m-d H:i'));
    }

    public function test_one_night_is_the_minimum(): void
    {
        $this->book($this->unit(), ['days' => 0])->assertSessionHasErrors(['days' => 'Un séjour dure au moins une nuit (24 h).']);
        $this->assertSame(0, Stay::count());
    }

    public function test_only_a_furnished_unit_with_a_daily_rate_can_be_booked(): void
    {
        $unit = $this->unit(['furnished' => false]);

        $this->book($unit)->assertSessionHasErrors('unit_id');
        $this->assertSame(0, Stay::count());
    }

    public function test_overlapping_stays_are_refused_but_back_to_back_stays_are_allowed(): void
    {
        $unit = $this->unit();
        $this->book($unit)->assertSessionHasNoErrors();

        $this->book($unit, ['check_in_at' => '2026-11-13T20:00', 'phone' => '699000002', 'name' => 'Autre'])
            ->assertSessionHasErrors('check_in_at');

        // Libéré le 14 à 14 h : une arrivée à 14 h est possible.
        $this->book($unit, ['check_in_at' => '2026-11-14T14:00', 'days' => 1, 'paid_amount' => 25000, 'phone' => '699000002', 'name' => 'Autre'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Stay::count());
    }

    public function test_a_unit_rented_by_the_month_cannot_be_booked_by_the_night(): void
    {
        $unit = $this->unit();
        app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Locataire']), ['start_date' => '2026-11-01', 'due_day' => 5, 'rent' => 150000, 'deposit_amount' => 0]);

        $this->book($unit)->assertSessionHasErrors(['check_in_at' => 'Ce logement est loué au mois à Locataire sur ces dates.']);
    }

    public function test_the_payment_cannot_exceed_the_stay_and_can_be_completed_later(): void
    {
        $unit = $this->unit();
        $this->book($unit, ['paid_amount' => 60000])->assertSessionHasErrors('paid_amount');

        $this->book($unit, ['paid_amount' => 20000])->assertSessionHasNoErrors();
        $stay = Stay::firstOrFail();
        $this->assertEquals(30000, $stay->balance());

        $this->post(route('stays.pay', $stay), ['amount' => 40000])->assertSessionHasErrors('amount');
        $this->post(route('stays.pay', $stay), ['amount' => 30000, 'method' => 'Mobile Money'])->assertSessionHasNoErrors();
        $this->assertTrue($stay->fresh()->isPaid());
        $this->assertSame('Mobile Money', $stay->fresh()->method);
    }

    public function test_the_whatsapp_thanks_gives_the_invoice_and_the_release_time(): void
    {
        $this->book($this->unit())->assertSessionHasNoErrors();
        $stay = Stay::firstOrFail();

        $response = $this->post(route('stays.message', [$stay, 'whatsapp']));
        $response->assertRedirect();
        $text = rawurldecode(explode('?text=', $response->headers->get('Location'))[1]);

        $this->assertStringStartsWith('https://wa.me/237699000001', $response->headers->get('Location'));
        $this->assertStringContainsString('MERCI POUR VOTRE SÉJOUR', $text);
        $this->assertStringContainsString($stay->number(), $text);
        $this->assertStringContainsString('Libération du logement : le 14/11/2026 à 14:00', $text);
        $this->assertStringContainsString('Durée : 2 jours (48 h)', $text);
        $this->assertStringContainsString('il vaut facture', $text);
        $this->assertNotNull($stay->fresh()->whatsapp_opened_at);

        $sms = $this->post(route('stays.message', [$stay, 'sms']))->headers->get('Location');
        $this->assertStringStartsWith('sms:+237699000001', $sms);
        $this->assertStringContainsString('Liberation le 14/11/2026 a 14:00', rawurldecode($sms));
    }

    public function test_the_invoice_prints_and_downloads(): void
    {
        $this->book($this->unit())->assertSessionHasNoErrors();
        $stay = Stay::firstOrFail();

        $this->get(route('stays.invoice', $stay))->assertOk()
            ->assertSee('Facture de séjour')->assertSee($stay->number())->assertSee('14/11/2026 14:00')->assertSee('Paul Client')->assertSee('Climatisation');

        $pdf = $this->get(route('stays.invoice.pdf', $stay));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_a_known_guest_is_reused_and_the_history_filters_by_guest_and_period(): void
    {
        $unit = $this->unit();
        $this->book($unit)->assertSessionHasNoErrors();
        $this->book($unit, ['check_in_at' => '2026-12-01T12:00', 'days' => 3, 'paid_amount' => 75000, 'phone' => '+237 699 000 001'])->assertSessionHasNoErrors();
        $this->book($unit, ['check_in_at' => '2026-12-10T12:00', 'days' => 1, 'paid_amount' => 25000, 'name' => 'Marie Autre', 'phone' => '699000009'])->assertSessionHasNoErrors();

        $this->assertSame(2, Guest::count());
        $paul = Guest::where('name', 'Paul Client')->firstOrFail();
        $this->assertSame(2, $paul->stays()->count());

        $this->get(route('stays.index', ['guest_id' => $paul->id]))->assertOk()
            ->assertSee('2 séjour(s), 5 jour(s)')->assertDontSee('Marie Autre</td>', false);

        $this->get(route('stays.index', ['from' => '2026-12-01', 'to' => '2026-12-31']))->assertOk()
            ->assertSee('2 séjour(s), 4 jour(s)');

        $this->get(route('stays.report', ['guest_id' => $paul->id, 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()
            ->assertSee('Historique des séjours')->assertSee('Du 01/11/2026 au 30/11/2026')->assertSee('1 séjour(s), 2 jour(s)');

        $pdf = $this->get(route('stays.report.pdf', ['guest_id' => $paul->id]));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_unit_shows_its_current_stay(): void
    {
        $unit = $this->unit();
        $this->book($unit, ['check_in_at' => '2026-11-12T08:00', 'days' => 1, 'paid_amount' => 25000])->assertSessionHasNoErrors();

        $this->get(route('units.show', $unit))->assertOk()->assertSee('En séjour · libre le 13/11/2026 08:00')->assertSee('Paul Client');
    }

    public function test_another_organisation_cannot_see_a_stay(): void
    {
        $this->book($this->unit())->assertSessionHasNoErrors();
        $stay = Stay::firstOrFail();

        $this->actingAs($this->owner('Beta'));
        $this->get(route('stays.show', $stay))->assertNotFound();
        $this->get(route('stays.invoice.pdf', $stay))->assertNotFound();
        $this->get(route('stays.index'))->assertDontSee('Paul Client');
    }
}
