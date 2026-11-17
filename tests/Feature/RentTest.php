<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->user = $this->owner('Résidence Alpha');
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(string $name): User
    {
        $organisation = Organisation::create(['name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF', 'phone_country' => 'CM']);

        return $organisation->users()->create(['name' => 'Proprio', 'email' => str($name)->slug().'@t.test', 'password' => 'secret-pass-1']);
    }

    private function unit(string $number = '1', float $rent = 50000): Unit
    {
        $property = Property::firstOrCreate(['name' => 'Immeuble A']);

        return Unit::create(['property_id' => $property->id, 'number' => $number, 'type' => 'apartment', 'rent' => $rent]);
    }

    private function lease(array $overrides = [], ?Unit $unit = null): Lease
    {
        $tenant = Tenant::create(['name' => 'Jean Locataire', 'phone' => '+237699000000']);

        return app(RentService::class)->startLease($unit ?? $this->unit(), $tenant, $overrides + [
            'start_date' => '2026-11-05',
            'due_day' => 5,
            'rent' => 50000,
            'deposit_amount' => 100000,
            'deposit_paid_at' => '2026-11-05',
        ]);
    }

    private function pay(Lease $lease, float $amount, string $date = '2026-11-10')
    {
        return $this->post(route('payments.store', $lease), ['amount' => $amount, 'paid_at' => $date, 'method' => 'Espèces']);
    }

    private function periods(Lease $lease): array
    {
        return $lease->invoices()->orderBy('period')->pluck('paid_amount', 'period')->map(fn ($amount) => (float) $amount)->all();
    }

    public function test_owner_adds_a_property_and_numbered_units(): void
    {
        $this->post(route('properties.store'), ['name' => 'Résidence Palmiers'])->assertSessionHasNoErrors();
        $property = Property::where('name', 'Résidence Palmiers')->firstOrFail();

        $this->post(route('units.store'), ['property_id' => $property->id, 'number' => 'A1', 'type' => 'room', 'rent' => 35000])->assertSessionHasNoErrors();
        $this->post(route('units.store'), ['property_id' => $property->id, 'number' => 'A1', 'type' => 'room', 'rent' => 35000])->assertSessionHasErrors('number');

        $this->get(route('properties.show', $property))->assertOk()->assertSee('A1')->assertSee('Chambre')->assertSee('35 000');
    }

    public function test_entry_creates_the_tenant_the_deposit_and_the_first_invoice(): void
    {
        $unit = $this->unit();

        $this->post(route('leases.store', $unit), [
            'tenant_mode' => 'new',
            'tenant' => ['name' => 'Awa', 'phone' => '699 11 22 33'],
            'start_date' => '2026-11-08',
            'due_day' => 8,
            'rent' => 50000,
            'deposit_amount' => 100000,
            'deposit_paid_at' => '2026-11-08',
        ])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $this->assertSame('+237699112233', $lease->tenant->phone);
        $this->assertSame(100000.0, $lease->depositHeld());
        $this->assertSame('2026-11', $lease->first_period);

        $invoice = $lease->invoices()->sole();
        $this->assertSame('2026-11', $invoice->period);
        $this->assertSame('2026-11-08', $invoice->due_date->toDateString());
        $this->assertSame(50000.0, (float) $invoice->amount);

        $this->get(route('leases.show', $lease))->assertOk()->assertSee('Awa')->assertSee('100 000');
    }

    public function test_an_occupied_unit_refuses_a_second_tenant_and_a_refused_entry_adds_nobody(): void
    {
        $unit = $this->unit();
        $this->lease([], $unit);
        $count = Tenant::count();

        $this->post(route('leases.store', $unit), [
            'tenant_mode' => 'new', 'tenant' => ['name' => 'Second'], 'start_date' => '2026-11-10', 'due_day' => 10, 'rent' => 50000,
        ])->assertSessionHasErrors('unit_id');

        $this->assertSame($count, Tenant::count());
        $this->assertSame(1, Lease::count());
    }

    public function test_each_tenant_has_a_due_date_and_short_months_are_handled(): void
    {
        $first = $this->lease(['start_date' => '2026-11-03', 'due_day' => 3], $this->unit('1'));
        $second = $this->lease(['start_date' => '2026-10-31', 'due_day' => 31], $this->unit('2'));

        $this->assertSame('2026-11-03', $first->invoices()->sole()->due_date->toDateString());

        $this->assertSame('2026-11-30', $second->invoices()->where('period', '2026-11')->sole()->due_date->toDateString());
        $this->assertSame('2027-02-28', $second->dueDateFor('2027-02')->toDateString());
    }

    public function test_first_invoice_never_falls_before_the_entry(): void
    {
        $lease = $this->lease(['start_date' => '2026-11-08', 'due_day' => 2]);

        $this->assertSame('2026-11-08', $lease->invoices()->sole()->due_date->toDateString());
        $this->assertSame('2026-12-02', $lease->dueDateFor('2026-12')->toDateString());
    }

    public function test_tenant_already_in_place_starts_at_the_chosen_month(): void
    {
        $lease = $this->lease(['start_date' => '2020-01-15', 'first_period' => '2026-10', 'due_day' => 15]);

        $this->assertSame(['2026-10', '2026-11'], $lease->invoices()->orderBy('period')->pluck('period')->all());
    }

    public function test_invoices_are_created_on_the_first_visit_of_the_new_month(): void
    {
        $lease = $this->lease();

        Carbon::setTestNow('2027-01-02 08:00:00');
        $this->assertSame(1, $lease->invoices()->count());

        $this->get(route('dashboard'))->assertOk();

        $this->assertSame(['2026-11', '2026-12', '2027-01'], $lease->invoices()->orderBy('period')->pluck('period')->all());
    }

    public function test_next_month_invoice_exists_as_soon_as_its_reminder_is_due(): void
    {
        $lease = $this->lease(['start_date' => '2026-11-01', 'due_day' => 1]);

        Carbon::setTestNow('2026-11-27 08:00:00');
        $this->get(route('dashboard'));
        $this->assertSame(['2026-11'], $lease->invoices()->pluck('period')->all());

        Carbon::setTestNow('2026-11-28 08:00:00');
        $this->get(route('dashboard'));
        $this->assertSame(['2026-11', '2026-12'], $lease->invoices()->orderBy('period')->pluck('period')->all());
    }

    public function test_month_by_month_and_partial_payments(): void
    {
        $lease = $this->lease();

        $this->pay($lease, 20000)->assertSessionHasNoErrors();
        $this->assertSame(['2026-11' => 20000.0], $this->periods($lease));

        $this->pay($lease, 30000)->assertSessionHasNoErrors();
        $this->assertSame(['2026-11' => 50000.0], $this->periods($lease));
        $this->assertSame(0.0, $lease->unpaidTotal());
    }

    public function test_advance_covers_several_future_months(): void
    {
        $lease = $this->lease();

        $this->pay($lease, 200000)->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'novembre 2026') && str_contains($message, 'février 2027'));

        $this->assertSame([
            '2026-11' => 50000.0, '2026-12' => 50000.0, '2027-01' => 50000.0, '2027-02' => 50000.0,
        ], $this->periods($lease));

        $invoice = $lease->invoices()->where('period', '2027-02')->sole();
        $this->assertTrue($invoice->isPrepaid());
        $this->assertSame('2027-02-05', $invoice->due_date->toDateString());
    }

    public function test_payment_settles_the_oldest_months_first_and_a_remainder_is_a_partial_advance(): void
    {
        $lease = $this->lease(['start_date' => '2026-09-05']);
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $lease->invoices()->orderBy('period')->pluck('period')->all());

        $this->pay($lease, 170000)->assertSessionHasNoErrors();

        $this->assertSame([
            '2026-09' => 50000.0, '2026-10' => 50000.0, '2026-11' => 50000.0, '2026-12' => 20000.0,
        ], $this->periods($lease));
    }

    public function test_generation_does_not_duplicate_months_paid_in_advance(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 150000);

        Carbon::setTestNow('2027-02-01 08:00:00');
        $this->get(route('dashboard'))->assertOk();

        $this->assertSame([
            '2026-11' => 50000.0, '2026-12' => 50000.0, '2027-01' => 50000.0, '2027-02' => 0.0,
        ], $this->periods($lease));
    }

    public function test_cancelling_a_payment_removes_unpaid_future_months_and_leaves_no_hole(): void
    {
        $lease = $this->lease(['start_date' => '2026-10-05']);

        $this->pay($lease, 50000, '2026-10-06');
        $this->pay($lease, 100000, '2026-11-06');
        $this->pay($lease, 50000, '2026-11-10');
        $this->assertSame(['2026-10' => 50000.0, '2026-11' => 50000.0, '2026-12' => 50000.0, '2027-01' => 50000.0], $this->periods($lease));

        $first = Payment::orderBy('id')->first();
        $this->delete(route('payments.destroy', $first))->assertSessionHasNoErrors();

        // Les paiements restants repartent du mois le plus ancien.
        $this->assertSame(['2026-10' => 50000.0, '2026-11' => 50000.0, '2026-12' => 50000.0], $this->periods($lease));
        $this->assertSame(3, \App\Models\PaymentAllocation::count());
    }

    public function test_correcting_a_payment_redistributes_the_months(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 150000);
        $payment = Payment::firstOrFail();

        $this->put(route('payments.update', $payment), ['amount' => 60000, 'paid_at' => '2026-11-10'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-11' => 50000.0, '2026-12' => 10000.0], $this->periods($lease));
    }

    public function test_changing_the_rent_keeps_existing_invoices_and_applies_to_the_next(): void
    {
        $lease = $this->lease();

        $this->put(route('leases.update', $lease), ['rent' => 60000, 'due_day' => 10, 'deposit_amount' => 100000])->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-12-02 08:00:00');
        $this->get(route('dashboard'));

        $invoices = $lease->invoices()->orderBy('period')->get();
        $this->assertSame(50000.0, (float) $invoices[0]->amount);
        $this->assertSame('2026-11-05', $invoices[0]->due_date->toDateString());
        $this->assertSame(60000.0, (float) $invoices[1]->amount);
        $this->assertSame('2026-12-10', $invoices[1]->due_date->toDateString());
    }

    public function test_correcting_an_invoice_moves_the_surplus_to_the_next_month(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 50000);
        $invoice = $lease->invoices()->sole();

        $this->put(route('invoices.update', $invoice), ['amount' => 40000, 'due_date' => '2026-11-05'])->assertSessionHasNoErrors();

        $this->assertSame(['2026-11' => 40000.0, '2026-12' => 10000.0], $this->periods($lease));
    }

    public function test_exit_frees_the_unit_settles_the_deposit_and_keeps_the_debt(): void
    {
        $unit = $this->unit();
        $lease = $this->lease(['start_date' => '2026-10-05'], $unit);

        $this->post(route('leases.end', $lease), [
            'end_date' => '2026-11-09', 'deposit_refunded' => 70000, 'deposit_retained' => 30000, 'deposit_note' => 'Peinture',
        ])->assertSessionHasNoErrors();

        $lease->refresh();
        $this->assertFalse($lease->isActive());
        $this->assertSame(0.0, $lease->depositHeld());
        $this->assertSame(100000.0, $lease->unpaidTotal());
        $this->assertSame(1, Unit::vacant()->count());

        Carbon::setTestNow('2027-01-02 08:00:00');
        $this->get(route('dashboard'));
        $this->assertSame(2, $lease->invoices()->count());
    }

    public function test_exit_refuses_a_deposit_settlement_above_the_deposit(): void
    {
        $lease = $this->lease();

        $this->post(route('leases.end', $lease), ['end_date' => '2026-11-09', 'deposit_refunded' => 90000, 'deposit_retained' => 20000])
            ->assertSessionHasErrors('deposit_refunded');

        $this->assertNull($lease->fresh()->end_date);
    }

    // Un mois payé d'avance n'empêche plus la sortie : ce qui le couvrait est rendu au locataire, à part.
    public function test_exit_refunds_a_later_month_paid_in_advance(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 150000);

        $this->post(route('leases.end', $lease), ['end_date' => '2026-11-30'])->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertNotNull($fresh->end_date);
        // Loyer de 50 000 : novembre reste, décembre et janvier sont rendus.
        $this->assertSame(100000.0, (float) $fresh->rentRefunds()->sum('amount'));
        $this->assertSame(150000.0, (float) $fresh->payments()->sum('amount'));
        $this->assertSame(0.0, $fresh->unpaidTotal());
    }

    public function test_exit_removes_unpaid_later_months_and_can_be_cancelled(): void
    {
        $lease = $this->lease(['start_date' => '2026-11-01', 'due_day' => 1]);
        Carbon::setTestNow('2026-11-28 08:00:00');
        $this->get(route('dashboard'));
        $this->assertSame(2, $lease->invoices()->count());

        $this->post(route('leases.end', $lease), ['end_date' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-11'], $lease->invoices()->pluck('period')->all());

        $this->post(route('leases.reopen', $lease))->assertSessionHasNoErrors();
        $this->assertNull($lease->fresh()->end_date);
        $this->assertSame(['2026-11', '2026-12'], $lease->invoices()->orderBy('period')->pluck('period')->all());
    }

    public function test_ended_lease_refuses_an_advance_beyond_its_debt(): void
    {
        $lease = $this->lease();
        $this->post(route('leases.end', $lease), ['end_date' => '2026-11-09'])->assertSessionHasNoErrors();

        $this->pay($lease, 60000)->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());

        $this->pay($lease, 50000)->assertSessionHasNoErrors();
        $this->assertSame(0.0, $lease->unpaidTotal());
    }

    public function test_absurd_advance_and_decimals_are_refused(): void
    {
        $lease = $this->lease();

        $this->pay($lease, 50000 * 70)->assertSessionHasErrors('amount');
        $this->pay($lease, 100.5)->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
        $this->assertSame(1, $lease->invoices()->count());
    }

    public function test_deposit_is_not_counted_as_rent(): void
    {
        $lease = $this->lease();

        $this->assertSame(50000.0, $lease->unpaidTotal());
        $page = $this->get(route('dashboard'))->assertOk();
        $this->assertSame(100000.0, $page->original->getData()['depositsHeld']);
        $this->assertSame(0.0, $page->original->getData()['collected']);
    }

    public function test_another_owner_sees_nothing(): void
    {
        $lease = $this->lease();
        $invoice = $lease->invoices()->sole();
        $this->pay($lease, 10000);
        $payment = Payment::firstOrFail();
        $propertyId = $lease->unit->property_id;

        $this->actingAs($this->owner('Résidence Beta'));

        $this->get(route('leases.show', $lease))->assertNotFound();
        $this->get(route('units.show', $lease->unit_id))->assertNotFound();
        $this->get(route('tenants.show', $lease->tenant_id))->assertNotFound();
        $this->get(route('invoices.show', $invoice))->assertNotFound();
        $this->get(route('invoices.pdf', $invoice))->assertNotFound();
        $this->post(route('payments.store', $lease), ['amount' => 1000, 'paid_at' => '2026-11-10'])->assertNotFound();
        $this->delete(route('payments.destroy', $payment))->assertNotFound();
        $this->post(route('messages.reminder', [$invoice, 'whatsapp']))->assertNotFound();
        $this->post(route('messages.late', [$lease, 'sms']))->assertNotFound();

        $this->post(route('units.store'), ['property_id' => $propertyId, 'number' => '9', 'type' => 'room', 'rent' => 1000])->assertSessionHasErrors('property_id');
        $this->assertSame(1, Unit::withoutGlobalScopes()->where('property_id', $propertyId)->count());

        $this->get(route('messages.index'))->assertOk()->assertDontSee('Jean Locataire');
    }

    public function test_every_page_opens(): void
    {
        $lease = $this->lease();
        $invoice = $lease->invoices()->sole();
        $this->pay($lease, 10000);
        $payment = Payment::firstOrFail();

        foreach ([
            route('dashboard'), route('properties.index'), route('properties.create'), route('properties.show', $lease->unit->property_id), route('properties.edit', $lease->unit->property_id),
            route('units.index'), route('units.index', ['etat' => 'libres']), route('units.create'), route('units.show', $lease->unit_id), route('units.edit', $lease->unit_id),
            route('tenants.index'), route('tenants.create'), route('tenants.show', $lease->tenant_id), route('tenants.edit', $lease->tenant_id),
            route('leases.show', $lease), route('leases.edit', $lease), route('leases.exit', $lease),
            route('invoices.index'), route('invoices.month'), route('invoices.show', $invoice), route('invoices.edit', $invoice),
            route('unpaid.index'), route('payments.index'), route('payments.edit', $payment),
            route('messages.index'), route('messages.index', ['etat' => 'factures']), route('messages.index', ['etat' => 'paiements']), route('messages.index', ['etat' => 'retards']),
            route('settings.edit'), route('subscription.index'),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('leases.create', $this->unit('2')))->assertOk();
        $this->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_settings_save_the_reminder_delay(): void
    {
        $this->put(route('settings.update'), ['name' => 'Résidence Alpha', 'currency' => 'XAF', 'phone_country' => 'CM', 'reminder_days' => 5])->assertSessionHasNoErrors();
        $this->assertSame(5, (int) $this->user->organisation->fresh()->reminder_days);

        $this->put(route('settings.update'), ['name' => 'Résidence Alpha', 'currency' => 'XAF', 'reminder_days' => 0])->assertSessionHasErrors('reminder_days');
    }

    public function test_unit_with_a_tenant_history_cannot_be_deleted(): void
    {
        $lease = $this->lease();

        $this->delete(route('units.destroy', $lease->unit_id))->assertSessionHas('error');
        $this->delete(route('tenants.destroy', $lease->tenant_id))->assertSessionHas('error');
        $this->delete(route('properties.destroy', $lease->unit->property_id))->assertSessionHas('error');

        $this->assertSame(1, Unit::count());
        $this->assertSame(1, Invoice::count());
    }
}
