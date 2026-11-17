<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\PropertyFigures;
use App\Services\RentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PropertyDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->actingAs($this->owner('Alpha'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(string $name): User
    {
        $organisation = Organisation::create(['name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF']);

        return $organisation->users()->create(['name' => 'P', 'email' => strtolower($name).'@t.test', 'password' => 'secret-pass-1']);
    }

    private function property(): Property
    {
        $property = Property::create(['name' => 'Palmiers', 'city' => 'Douala', 'neighbourhood' => 'Bonapriso']);

        foreach ([['A1', 'apartment', 100000], ['A2', 'apartment', 120000], ['C1', 'room', 30000]] as [$number, $type, $rent]) {
            Unit::create(['property_id' => $property->id, 'number' => $number, 'type' => $type, 'rent' => $rent]);
        }

        return $property;
    }

    private function enter(Property $property, string $number, float $rent, string $start = '2026-11-01', float $deposit = 0): Lease
    {
        return app(RentService::class)->startLease(
            $property->units()->where('number', $number)->firstOrFail(),
            Tenant::create(['name' => 'Locataire '.$number]),
            ['start_date' => $start, 'due_day' => 5, 'rent' => $rent, 'deposit_amount' => $deposit],
        );
    }

    public function test_location_is_saved_and_shown(): void
    {
        $this->post(route('properties.store'), [
            'name' => 'Palmiers', 'city' => 'Douala', 'neighbourhood' => 'Bonapriso', 'address' => 'Derrière la pharmacie',
            'latitude' => '4.0186000', 'longitude' => '9.6951000',
        ])->assertSessionHasNoErrors();

        $property = Property::firstOrFail();
        $this->assertTrue($property->hasPosition());
        $this->assertSame('Bonapriso, Douala', $property->place());
        $this->assertSame('https://www.google.com/maps?q=4.0186,9.6951', $property->mapUrl());

        $this->get(route('properties.show', $property))->assertOk()
            ->assertSee('Derrière la pharmacie')->assertSee('property-map')->assertSee('google.com/maps?q=4.0186,9.6951');
        $this->get(route('properties.edit', $property))->assertOk()->assertSee('id="picker"', false);
    }

    public function test_position_is_optional_but_needs_both_coordinates_within_range(): void
    {
        $this->post(route('properties.store'), ['name' => 'Sans position'])->assertSessionHasNoErrors();
        $this->get(route('properties.show', Property::firstOrFail()))->assertOk()->assertSee('Ajouter sa position')->assertDontSee('property-map');

        $this->post(route('properties.store'), ['name' => 'A', 'latitude' => 4])->assertSessionHasErrors('longitude');
        $this->post(route('properties.store'), ['name' => 'B', 'longitude' => 9])->assertSessionHasErrors('latitude');
        $this->post(route('properties.store'), ['name' => 'C', 'latitude' => 95, 'longitude' => 9])->assertSessionHasErrors('latitude');
        $this->post(route('properties.store'), ['name' => 'D', 'latitude' => 4, 'longitude' => 200])->assertSessionHasErrors('longitude');
    }

    public function test_monthly_income_separates_current_estimated_and_shortfall(): void
    {
        $property = $this->property();
        $this->enter($property, 'A1', 110000);

        $figures = app(PropertyFigures::class)->forProperty($property);

        $this->assertSame(110000.0, $figures['current']);
        // A1 au loyer de son bail, A2 et C1 au montant du logement.
        $this->assertSame(260000.0, $figures['estimated']);
        $this->assertSame(150000.0, $figures['shortfall']);
        $this->assertSame(1320000.0, $figures['yearly']);
        $this->assertSame(1, $figures['occupied']);
        $this->assertSame(2, $figures['vacant']);
        $this->assertSame(33, $figures['occupancy']);
    }

    public function test_details_count_apartments_and_rooms(): void
    {
        $property = $this->property();
        $this->enter($property, 'C1', 30000);

        $types = app(PropertyFigures::class)->forProperty($property)['types'];

        $this->assertCount(2, $types);
        $this->assertSame('Appartement', $types[0]->label);
        $this->assertSame(2, $types[0]->total);
        $this->assertSame(0, $types[0]->occupied);
        $this->assertSame(220000.0, $types[0]->estimated);
        $this->assertSame('Chambre', $types[1]->label);
        $this->assertSame(1, $types[1]->occupied);
        $this->assertSame(30000.0, $types[1]->current);

        $table = $this->get(route('properties.index'))->assertOk()->assertSee('Douala')->original->getData()['properties'];
        $row = $table->items()[0];
        $this->assertSame(2, (int) $row->apartments_count);
        $this->assertSame(1, (int) $row->rooms_count);
        $this->assertSame(30000.0, (float) $row->current_income);
        $this->assertSame(250000.0, (float) $row->estimated_income);
    }

    public function test_income_follows_the_exit_of_a_tenant(): void
    {
        $property = $this->property();
        $lease = $this->enter($property, 'A1', 110000);

        app(RentService::class)->endLease($lease, ['end_date' => '2026-11-09']);

        $figures = app(PropertyFigures::class)->forProperty($property);
        $this->assertSame(0.0, $figures['current']);
        // Le logement libre revient à son montant, pas au loyer de l'ancien bail.
        $this->assertSame(250000.0, $figures['estimated']);
    }

    public function test_collected_counts_advances_in_the_month_received_and_deposits_stay_apart(): void
    {
        $property = $this->property();
        $lease = $this->enter($property, 'A1', 100000, '2026-11-01', 200000);
        app(RentService::class)->recordPayment($lease, 300000, '2026-11-10', null, null);

        $figures = app(PropertyFigures::class)->forProperty($property);

        $this->assertSame(300000.0, $figures['collected']);
        $this->assertSame(100000.0, $figures['billed']);
        $this->assertSame(200000.0, $figures['deposits']);

        Carbon::setTestNow('2026-12-02 09:00:00');
        $this->assertSame(0.0, app(PropertyFigures::class)->forProperty($property)['collected']);
    }

    public function test_cancelled_payment_leaves_the_income_history(): void
    {
        $property = $this->property();
        $lease = $this->enter($property, 'A1', 100000);
        $payment = app(RentService::class)->recordPayment($lease, 100000, '2026-11-10', null, null);

        app(RentService::class)->cancelPayment($payment);

        $this->assertSame(0.0, app(PropertyFigures::class)->forProperty($property)['collected']);
    }

    public function test_history_lists_six_months_with_billed_and_paid(): void
    {
        $property = $this->property();
        $lease = $this->enter($property, 'A1', 100000, '2026-09-01');
        app(RentService::class)->recordPayment($lease, 150000, '2026-11-10', null, null);

        $history = app(PropertyFigures::class)->forProperty($property)['history'];

        $this->assertCount(6, $history);
        $this->assertSame(['2026-11', '2026-10', '2026-09', '2026-08', '2026-07', '2026-06'], $history->pluck('period')->all());
        $this->assertSame(100000.0, $history[2]->paid);
        $this->assertSame(50000.0, $history[1]->paid);
        $this->assertSame(50000.0, $history[1]->rest);
        $this->assertSame(100000.0, $history[0]->rest);
        $this->assertSame(0.0, $history[3]->billed);
    }

    public function test_dashboard_and_list_show_the_totals(): void
    {
        $property = $this->property();
        $this->enter($property, 'A1', 100000);

        $income = $this->get(route('dashboard'))->assertOk()->original->getData()['income'];
        $this->assertSame(100000.0, $income['current']);
        $this->assertSame(250000.0, $income['estimated']);
        $this->assertSame(150000.0, $income['shortfall']);

        $this->get(route('properties.index'))->assertOk()->assertSee('Revenu mensuel actuel')->assertSee('250 000');
    }

    public function test_a_property_can_be_a_single_unit_created_with_its_details(): void
    {
        $this->post(route('properties.store'), [
            'name' => 'Studio Akwa', 'kind' => 'studio', 'rent' => 75000, 'surface' => 28.5, 'floor' => '2e', 'bedrooms' => 1,
            'bathrooms' => 1, 'furnished' => 1, 'amenities' => ['water', 'electricity'],
        ])->assertSessionHasNoErrors();

        $property = Property::where('name', 'Studio Akwa')->firstOrFail();
        $unit = $property->units()->sole();

        $this->assertTrue($property->isSingle());
        $this->assertSame('studio', $unit->type);
        $this->assertSame(75000.0, (float) $unit->rent);
        $this->assertTrue($unit->furnished);
        $this->assertSame('Studio Akwa', $unit->label());
        $this->assertStringContainsString('28,5 m²', $unit->summary());

        $this->get(route('properties.show', $property))->assertOk()
            ->assertSee('28,5 m²')->assertSee('Eau courante')->assertSee('Enregistrer une entrée')->assertDontSee('Ajouter un logement');
    }

    public function test_single_unit_property_needs_a_rent_and_creates_nothing_otherwise(): void
    {
        $this->post(route('properties.store'), ['name' => 'Chambre X', 'kind' => 'room'])->assertSessionHasErrors('rent');

        $this->assertSame(0, Property::count());
    }

    public function test_single_unit_property_refuses_a_second_unit_until_it_becomes_a_group(): void
    {
        $this->post(route('properties.store'), ['name' => 'Chambre X', 'kind' => 'room', 'rent' => 30000])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();
        $second = ['property_id' => $property->id, 'number' => '2', 'type' => 'room', 'rent' => 30000];

        $this->post(route('units.store'), $second)->assertSessionHasErrors('property_id');
        $this->assertSame(1, Unit::count());

        $this->put(route('properties.update', $property), ['name' => 'Chambre X', 'kind' => 'mixed'])->assertSessionHasNoErrors();
        $this->post(route('units.store'), $second)->assertSessionHasNoErrors();
        $this->assertSame(2, Unit::count());

        $this->put(route('properties.update', $property), ['name' => 'Chambre X', 'kind' => 'room'])->assertSessionHasErrors('kind');
        $this->assertSame('mixed', $property->fresh()->kind);
    }

    public function test_changing_the_kind_of_a_single_property_updates_its_unit(): void
    {
        $this->post(route('properties.store'), ['name' => 'Bien', 'kind' => 'room', 'rent' => 30000])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();

        $this->put(route('properties.update', $property), ['name' => 'Bien', 'kind' => 'studio'])->assertSessionHasNoErrors();

        $this->assertSame('studio', $property->units()->sole()->type);
    }

    public function test_group_mixes_apartments_rooms_and_studios_each_with_its_details(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed'])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();
        $this->assertSame(0, $property->units()->count());

        foreach ([['A1', 'apartment', 150000, 3], ['C1', 'room', 30000, 1], ['S1', 'studio', 70000, 1]] as [$number, $type, $rent, $bedrooms]) {
            $this->post(route('units.store'), ['property_id' => $property->id, 'number' => $number, 'type' => $type, 'rent' => $rent, 'bedrooms' => $bedrooms])->assertSessionHasNoErrors();
        }

        $this->assertCount(3, app(PropertyFigures::class)->forProperty($property)['types']);
        $this->get(route('properties.show', $property))->assertOk()->assertSee('3 chambres')->assertSee('1 chambre')->assertSee('Ensemble de logements');
    }

    private function groupRows(): array
    {
        return [
            ['type' => 'studio', 'number' => 'S1', 'rent' => 70000, 'bedrooms' => 1, 'surface' => 30, 'furnished' => 1, 'amenities' => ['water']],
            ['type' => 'apartment', 'number' => 'A1', 'rent' => 180000, 'bedrooms' => 3, 'living_rooms' => 1, 'kitchens' => 1, 'bathrooms' => 2, 'floor' => '1er'],
            ['type' => 'apartment', 'number' => 'A2', 'rent' => 130000, 'bedrooms' => 2, 'living_rooms' => 1],
        ];
    }

    public function test_group_is_created_with_each_unit_details_and_its_own_rate(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Bien ajouté avec 3 logement(s).');

        $property = Property::firstOrFail();
        $this->assertSame(3, $property->units()->count());

        $three = $property->units()->where('number', 'A1')->sole();
        $this->assertSame(180000.0, (float) $three->rent);
        $this->assertSame(3, (int) $three->bedrooms);
        $this->assertSame('1er', $three->floor);
        $studio = $property->units()->where('number', 'S1')->sole();
        $this->assertTrue($studio->furnished);
        $this->assertSame(['water'], $studio->amenities);

        $types = app(PropertyFigures::class)->forProperty($property)['types'];
        $this->assertSame(['Appartement · 2 chambres', 'Appartement · 3 chambres', 'Studio · 1 chambre'], $types->sortBy('label')->pluck('label')->values()->all());
        $this->assertSame(380000.0, app(PropertyFigures::class)->forProperty($property)['estimated']);
    }

    public function test_group_rows_are_validated_and_nothing_is_created_on_error(): void
    {
        $rows = $this->groupRows();
        unset($rows[1]['rent']);
        $rows[2]['number'] = 'S1';

        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $rows])
            ->assertSessionHasErrors(['units.1.rent', 'units.2.number']);

        $this->assertSame(0, Property::count());
        $this->assertSame(0, Unit::count());
    }

    public function test_status_shows_occupied_or_vacant(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();
        $this->enter($property, 'A1', 180000);

        $page = $this->get(route('properties.show', $property))->assertOk()->assertSee('Statut')->assertSee('Locataire A1');
        $this->assertSame(1, substr_count($page->getContent(), '<span class="badge ok">Occupé</span>'));
        $this->assertSame(2, substr_count($page->getContent(), '<span class="badge off">Libre</span>'));

        $this->get(route('properties.edit', $property))->assertOk()->assertSee('Locataire A1')->assertSee('Occupé');
        $this->get(route('units.index', ['etat' => 'libres']))->assertOk()->assertSee('S1')->assertDontSee('Locataire A1');
    }

    public function test_editing_a_group_updates_adds_removes_and_swaps_numbers(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();
        $s1 = $property->units()->where('number', 'S1')->sole();
        $a1 = $property->units()->where('number', 'A1')->sole();
        $a2 = $property->units()->where('number', 'A2')->sole();

        $this->put(route('properties.update', $property), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => [
            ['id' => $a1->id, 'type' => 'apartment', 'number' => 'A2', 'rent' => 190000, 'bedrooms' => 3],
            ['id' => $a2->id, 'type' => 'apartment', 'number' => 'A1', 'rent' => 130000, 'bedrooms' => 2],
            ['id' => $s1->id, 'delete' => '1'],
            ['type' => 'room', 'number' => 'C1', 'rent' => 30000],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('A2', $a1->fresh()->number);
        $this->assertSame(190000.0, (float) $a1->fresh()->rent);
        $this->assertSame('A1', $a2->fresh()->number);
        $this->assertNull(Unit::find($s1->id));
        $this->assertSame(1, $property->units()->where('number', 'C1')->count());
        $this->assertSame(3, $property->units()->count());
    }

    public function test_changing_a_rate_keeps_the_rent_of_the_current_lease(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();
        $lease = $this->enter($property, 'A1', 180000);

        $this->put(route('properties.update', $property), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => [
            ['id' => $lease->unit_id, 'type' => 'apartment', 'number' => 'A1', 'rent' => 200000],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(180000.0, (float) $lease->fresh()->rent);
        $this->assertSame(200000.0, (float) $lease->unit->fresh()->rent);
    }

    public function test_a_unit_with_a_tenant_history_cannot_be_removed_and_nothing_changes(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();
        $lease = $this->enter($property, 'A1', 180000);

        $this->put(route('properties.update', $property), ['name' => 'Nouveau nom', 'kind' => 'mixed', 'units' => [
            ['id' => $lease->unit_id, 'delete' => '1'],
        ]])->assertSessionHasErrors('units');

        $this->assertSame(3, $property->units()->count());
        $this->assertSame('Ensemble', $property->fresh()->name);
    }

    public function test_a_number_already_used_by_a_unit_left_out_is_refused(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])->assertSessionHasNoErrors();
        $property = Property::firstOrFail();

        $this->put(route('properties.update', $property), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => [
            ['type' => 'room', 'number' => 'S1', 'rent' => 30000],
        ]])->assertSessionHasErrors('units');
    }

    public function test_a_unit_of_another_property_cannot_be_changed_through_the_form(): void
    {
        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])->assertSessionHasNoErrors();
        $foreign = Property::firstOrFail()->units()->firstOrFail();
        $this->post(route('properties.store'), ['name' => 'Autre', 'kind' => 'mixed'])->assertSessionHasNoErrors();
        $other = Property::where('name', 'Autre')->firstOrFail();

        $this->put(route('properties.update', $other), ['name' => 'Autre', 'kind' => 'mixed', 'units' => [
            ['id' => $foreign->id, 'type' => 'room', 'number' => 'VOLE', 'rent' => 1],
        ]])->assertSessionHasErrors('units.0.id');

        $this->put(route('properties.update', $other), ['name' => 'Autre', 'kind' => 'mixed', 'units' => [
            ['id' => $foreign->id, 'delete' => '1'],
        ]])->assertNotFound();

        $this->assertNotNull(Unit::find($foreign->id));
        $this->assertNotSame('VOLE', $foreign->fresh()->number);
    }

    public function test_form_offers_the_unit_rows_when_creating_and_editing(): void
    {
        $this->get(route('properties.create'))->assertOk()->assertSee('data-unit-row', false)->assertSee('units[0][rent]', false)->assertSee('Tarif par mois');

        $this->post(route('properties.store'), ['name' => 'Ensemble', 'kind' => 'mixed', 'units' => $this->groupRows()])->assertSessionHasNoErrors();

        $this->get(route('properties.edit', Property::firstOrFail()))->assertOk()->assertSee('value="S1"', false)->assertSee('value="A2"', false)->assertSee('value="180000"', false);
    }

    public function test_unit_details_can_be_cleared_and_unknown_amenities_are_refused(): void
    {
        $property = $this->property();
        $unit = $property->units()->firstOrFail();
        $base = ['property_id' => $property->id, 'number' => $unit->number, 'type' => $unit->type, 'rent' => 100000];

        $this->put(route('units.update', $unit), $base + ['furnished' => 1, 'amenities' => ['parking']])->assertSessionHasNoErrors();
        $this->assertTrue($unit->fresh()->furnished);
        $this->assertSame(['Parking'], $unit->fresh()->amenityLabels());

        $this->put(route('units.update', $unit), $base)->assertSessionHasNoErrors();
        $this->assertFalse($unit->fresh()->furnished);
        $this->assertSame([], $unit->fresh()->amenities);

        $this->put(route('units.update', $unit), $base + ['amenities' => ['piscine-secrete']])->assertSessionHasErrors('amenities.0');
    }

    public function test_map_shows_only_placed_properties_of_this_account(): void
    {
        $placed = $this->property();
        $placed->update(['latitude' => 4.0186, 'longitude' => 9.6951]);
        $this->enter($placed, 'A1', 100000);
        Property::create(['name' => 'Sans position']);

        $data = $this->get(route('map.index'))->assertOk()->assertSee('Palmiers')->original->getData();
        $this->assertCount(1, $data['points']);
        $this->assertSame('partial', $data['points'][0]['status']);
        $this->assertSame(1, $data['withoutPosition']);

        $this->actingAs($this->owner('Beta'));
        $this->get(route('map.index'))->assertOk()->assertDontSee('Palmiers');
        $this->get(route('properties.show', $placed))->assertNotFound();
        $this->assertSame(['current' => 0.0, 'estimated' => 0.0, 'shortfall' => 0], app(PropertyFigures::class)->totals());
    }
}
