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

class RegularisationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 09:00:00');
        $organisation = Organisation::create(['name' => 'Alpha', 'code' => 'ALP-001', 'currency' => 'XAF']);
        $this->user = $organisation->users()->create(['name' => 'P', 'email' => 'alpha@t.test', 'password' => 'secret-pass-1']);
        $this->actingAs($this->user);
        $property = Property::create(['name' => 'Immeuble']);
        $this->unit = Unit::create(['property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'rent' => 100000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // Tout en texte, comme un navigateur.
    private function enter(array $data)
    {
        return $this->post(route('leases.store', $this->unit), $data + [
            'tenant_mode' => 'new',
            'tenant' => ['name' => 'Ancien Client', 'phone' => '+237690000001'],
            'due_day' => '1',
            'billing_months' => '1',
            'rent' => '100000',
            'deposit_enabled' => '1',
            'deposit_months' => '2',
        ]);
    }

    public function test_one_payment_date_for_the_deposit_and_the_rest_in_rent(): void
    {
        $this->enter([
            'start_date' => '2026-03-01', 'first_period' => '2026-03',
            'deposit_paid_at' => '2026-02-20',
            'entry_months' => '10',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // La date d'entrée et la date du versement diffèrent, et aucune n'est le jour de la saisie.
        $lease = Lease::firstOrFail();
        $this->assertSame('2026-03-01', $lease->start_date->toDateString());
        $this->assertSame('2026-02-20', $lease->deposit_paid_at->toDateString());
        $this->assertSame('2026-02-20', $lease->depositMovements()->first()->happened_on->toDateString());
        $this->assertSame(200000.0, (float) $lease->deposit_amount);

        $payment = Payment::firstOrFail();
        $this->assertSame('2026-02-20', $payment->paid_at->toDateString());
        $this->assertSame(800000.0, (float) $payment->amount);
    }

    public function test_an_old_client_with_everything_paid_owes_nothing(): void
    {
        // Entré en mars : 2 mois de caution et 9 mois de loyer couvrent mars à novembre.
        $this->enter([
            'start_date' => '2026-03-01', 'first_period' => '2026-03', 'deposit_paid_at' => '2026-03-01',
            'entry_months' => '11',
        ])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $this->assertSame(0.0, $lease->unpaidTotal());
        $this->assertSame('30/11/2026', $lease->paidThrough()->format('d/m/Y'));
        $this->assertSame(200000.0, $lease->depositHeld());
    }

    public function test_months_not_covered_become_unpaid_from_the_oldest(): void
    {
        // Entré en mars : 5 mois de loyer payés. Août, septembre, octobre et novembre restent dus.
        $this->enter([
            'start_date' => '2026-03-01', 'first_period' => '2026-03', 'deposit_paid_at' => '2026-03-01',
            'entry_months' => '7',
        ])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $this->assertSame(['2026-08', '2026-09', '2026-10', '2026-11'], Invoice::where('lease_id', $lease->id)->unpaid()->orderBy('period')->pluck('period')->all());
        $this->assertSame(400000.0, $lease->unpaidTotal());
        $this->assertSame('31/07/2026', $lease->paidThrough()->format('d/m/Y'));
    }

    public function test_the_first_billed_month_can_skip_a_settled_past(): void
    {
        $this->enter(['start_date' => '2024-01-15', 'first_period' => '2026-11', 'deposit_paid_at' => '2024-01-10'])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $this->assertSame('2024-01-15', $lease->start_date->toDateString());
        $this->assertSame(['2026-11'], Invoice::where('lease_id', $lease->id)->pluck('period')->all());
        $this->assertSame('2024-01-10', $lease->depositMovements()->first()->happened_on->toDateString());
    }

    public function test_several_past_payments_can_be_added_with_their_own_date(): void
    {
        $this->enter(['start_date' => '2026-08-01', 'first_period' => '2026-08', 'deposit_paid_at' => '2026-08-01'])->assertSessionHasNoErrors();
        $lease = Lease::firstOrFail();
        $this->assertSame(400000.0, $lease->unpaidTotal());

        // Saisis dans le désordre : ils se répartissent quand même du plus ancien au plus récent.
        $this->post(route('payments.store', $lease), ['amount' => '100000', 'paid_at' => '2026-09-03'])->assertSessionHasNoErrors();
        $this->post(route('payments.store', $lease), ['amount' => '100000', 'paid_at' => '2026-08-02'])->assertSessionHasNoErrors();

        $august = Invoice::where('lease_id', $lease->id)->where('period', '2026-08')->first();
        $this->assertSame('2026-08-02', $august->allocations()->first()->payment->paid_at->toDateString());
        $this->assertSame('30/09/2026', $lease->fresh()->paidThrough()->format('d/m/Y'));
        $this->assertSame(200000.0, $lease->fresh()->unpaidTotal());
    }

    public function test_regularising_works_with_a_quarterly_cycle(): void
    {
        // Entré en mars, trimestriel, 2 mois de loyer payés : le reste forme des cycles de 3 mois à partir de mai.
        $this->enter([
            'start_date' => '2026-03-01', 'first_period' => '2026-03', 'billing_months' => '3', 'deposit_paid_at' => '2026-03-01',
            'entry_months' => '4',
        ])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $due = Invoice::where('lease_id', $lease->id)->unpaid()->orderBy('period')->get();

        $this->assertSame('2026-05', $due->first()->period);
        $this->assertSame('2026-05-01', $due->where('period', '2026-07')->first()->due_date->toDateString());
        $this->assertSame('2026-08-01', $due->where('period', '2026-08')->first()->due_date->toDateString());
        $this->assertSame(0, Invoice::where('lease_id', $lease->id)->selectRaw('period, COUNT(*) as n')->groupBy('period')->having('n', '>', 1)->get()->count());
    }

    public function test_an_old_date_without_a_rent_payment_only_records_the_deposit(): void
    {
        $this->enter(['start_date' => '2026-10-01', 'first_period' => '2026-10', 'deposit_paid_at' => '2026-09-25'])->assertSessionHasNoErrors();

        $this->assertSame(0, Payment::count());
        $this->assertSame('2026-09-25', Lease::firstOrFail()->deposit_paid_at->toDateString());
    }

    public function test_a_future_payment_date_is_refused(): void
    {
        $this->enter(['start_date' => '2026-11-01', 'deposit_paid_at' => '2026-12-01'])->assertSessionHasErrors('deposit_paid_at');
        $this->enter(['start_date' => '2026-11-01', 'deposit_paid_at' => '2026-12-01', 'entry_months' => '10'])->assertSessionHasErrors('deposit_paid_at');
        $this->enter(['start_date' => '2026-12-01', 'deposit_enabled' => '0', 'entry_months' => '3'])->assertSessionHasErrors('deposit_paid_at');

        $this->assertSame(0, Lease::count());
    }

    public function test_a_future_entry_stays_possible_with_a_past_deposit(): void
    {
        $this->enter(['start_date' => '2026-12-01', 'first_period' => '2026-12', 'deposit_paid_at' => '2026-11-09'])->assertSessionHasNoErrors();

        $this->assertSame('2026-12-01', Lease::firstOrFail()->start_date->toDateString());
    }

    public function test_without_a_payment_date_the_entry_date_is_used(): void
    {
        $this->enter(['start_date' => '2026-11-01', 'first_period' => '2026-11', 'entry_months' => '3'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-01', Payment::firstOrFail()->paid_at->toDateString());
    }

    public function test_disabling_the_deposit_keeps_the_rent_payment_date(): void
    {
        $this->enter(['start_date' => '2026-03-01', 'first_period' => '2026-03', 'deposit_enabled' => '0', 'entry_months' => '9', 'deposit_paid_at' => '2026-03-02'])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $this->assertNull($lease->deposit_paid_at);
        $this->assertSame('2026-03-02', Payment::firstOrFail()->paid_at->toDateString());
        $this->assertSame(0.0, $lease->unpaidTotal());
    }

    public function test_the_form_offers_the_entry_date_and_one_payment_date(): void
    {
        $this->get(route('leases.create', $this->unit))->assertOk()
            ->assertSee('name="start_date"', false)
            ->assertSee('name="deposit_paid_at"', false)
            ->assertSee('Date du versement')
            ->assertDontSee('name="entry_paid_at"', false);
    }

    public function test_the_entry_date_can_be_corrected_on_the_lease(): void
    {
        $this->enter(['start_date' => '2026-11-01', 'first_period' => '2026-11', 'deposit_paid_at' => '2026-11-01'])->assertSessionHasNoErrors();
        $lease = Lease::firstOrFail();
        $invoices = Invoice::where('lease_id', $lease->id)->pluck('due_date', 'period')->all();

        $base = ['due_day' => '1', 'billing_months' => '1', 'rent' => '100000', 'deposit_amount' => '200000', 'deposit_months' => '2', 'deposit_paid_at' => '2026-11-01'];

        $this->get(route('leases.edit', $lease))->assertOk()->assertSee('name="start_date"', false);
        $this->put(route('leases.update', $lease), $base + ['start_date' => '2025-06-15'])->assertSessionHasNoErrors();

        $this->assertSame('2025-06-15', $lease->fresh()->start_date->toDateString());
        $this->assertEquals($invoices, Invoice::where('lease_id', $lease->id)->pluck('due_date', 'period')->all());

        // Pas après le premier mois facturé.
        $this->put(route('leases.update', $lease), $base + ['start_date' => '2026-12-01'])->assertSessionHasErrors('start_date');
        $this->assertSame('2025-06-15', $lease->fresh()->start_date->toDateString());
    }

    public function test_the_entry_date_cannot_overlap_the_previous_tenant(): void
    {
        $rent = app(RentService::class);
        $previous = $rent->startLease($this->unit, Tenant::create(['name' => 'Avant']), ['start_date' => '2026-01-01', 'due_day' => 1, 'rent' => 100000]);
        $rent->endLease($previous, ['end_date' => '2026-06-30']);

        $lease = $rent->startLease($this->unit, Tenant::create(['name' => 'Apres']), ['start_date' => '2026-08-01', 'due_day' => 1, 'rent' => 100000]);
        $base = ['due_day' => '1', 'rent' => '100000', 'deposit_amount' => '0'];

        $this->put(route('leases.update', $lease), $base + ['start_date' => '2026-06-15'])->assertSessionHasErrors('start_date');
        $this->put(route('leases.update', $lease), $base + ['start_date' => '2026-07-01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-07-01', $lease->fresh()->start_date->toDateString());
    }

    public function test_a_corrected_payment_date_stays_possible(): void
    {
        $this->enter(['start_date' => '2026-03-01', 'first_period' => '2026-03', 'deposit_paid_at' => '2026-11-09', 'entry_months' => '4'])->assertSessionHasNoErrors();
        $payment = Payment::firstOrFail();

        $this->put(route('payments.update', $payment), ['amount' => '200000', 'paid_at' => '2026-03-02'])->assertSessionHasNoErrors();

        $this->assertSame('2026-03-02', $payment->fresh()->paid_at->toDateString());
    }
}
