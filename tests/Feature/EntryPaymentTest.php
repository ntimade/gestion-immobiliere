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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EntryPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
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

    private function enter(array $data = [])
    {
        return $this->post(route('leases.store', $this->unit), $data + [
            'tenant_mode' => 'new',
            'tenant' => ['name' => 'Jean', 'phone' => '690000001'],
            'start_date' => '2026-11-10',
            'first_period' => '2026-11',
            'due_day' => 10,
            'rent' => 100000,
            'deposit_paid_at' => '2026-11-10',
        ]);
    }

    public function test_ten_months_with_two_of_deposit_gives_eight_months_of_rent(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 2, 'entry_months' => 10, 'entry_method' => 'Espèces', 'entry_reference' => 'R-10'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $lease = Lease::firstOrFail();
        $this->assertSame(200000.0, (float) $lease->deposit_amount);
        $this->assertSame(2, (int) $lease->deposit_months);
        $this->assertSame(200000.0, $lease->depositHeld());
        $this->assertSame('2 mois de loyer', $lease->depositMovements()->first()->note);

        $payment = Payment::firstOrFail();
        $this->assertSame(800000.0, (float) $payment->amount);
        $this->assertSame('Espèces', $payment->method);
        $this->assertSame('R-10', $payment->reference);

        $paid = Invoice::where('lease_id', $lease->id)->whereColumn('paid_amount', '>=', 'amount')->orderBy('period')->pluck('period')->all();
        $this->assertCount(8, $paid);
        $this->assertSame('2026-11', $paid[0]);
        $this->assertSame('2027-06', $paid[7]);
        $this->assertSame(0.0, $lease->unpaidTotal());
    }

    // Un navigateur envoie tout en texte : c'est ce cas qui a provoqué une erreur 500 à l'entrée.
    public function test_the_entry_works_with_the_text_values_sent_by_a_browser(): void
    {
        foreach (['1', '3'] as $i => $cycle) {
            $unit = Unit::create(['property_id' => $this->unit->property_id, 'number' => 'T'.$i, 'type' => 'room', 'rent' => 35000]);

            $this->post(route('leases.store', $unit), [
                'tenant_mode' => 'new',
                'tenant' => ['name' => 'Texte '.$i],
                'start_date' => '2026-11-09',
                'first_period' => '2026-11',
                'due_day' => '9',
                'billing_months' => $cycle,
                'rent' => '35000',
                'deposit_enabled' => '1',
                'deposit_months' => '1',
                'deposit_amount' => '35000',
                'entry_months' => '10',
                'deposit_paid_at' => '2026-11-09',
            ])->assertSessionHasNoErrors()->assertRedirect()
                ->assertSessionHas('success', fn ($message) => str_contains($message, 'jusqu\'au 08/08/2027'));

            $lease = Lease::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(35000.0, (float) $lease->deposit_amount);
            $this->assertSame('08/08/2027', $lease->paidThrough()->format('d/m/Y'));
        }
    }

    public function test_the_deposit_is_never_counted_as_rent_on_the_statement(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 2, 'entry_months' => 10]);
        $lease = Lease::firstOrFail();

        $this->get(route('leases.statement', $lease))->assertOk()
            ->assertSee('800 000 XAF')
            ->assertSee('Caution versée')
            ->assertSee('2 mois de loyer')
            ->assertSee('Encore détenue : 200 000 XAF');
    }

    public function test_the_deposit_is_negotiable_per_client(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 3, 'entry_months' => 4]);
        $lease = Lease::firstOrFail();

        $this->assertSame(300000.0, (float) $lease->deposit_amount);
        $this->assertSame(100000.0, (float) Payment::firstOrFail()->amount);
    }

    public function test_the_deposit_always_follows_the_months_even_if_another_amount_is_sent(): void
    {
        $this->enter(['deposit_enabled' => '1', 'deposit_months' => '2', 'deposit_amount' => '150000', 'entry_months' => '10'])->assertSessionHasNoErrors();

        $this->assertSame(200000.0, (float) Lease::firstOrFail()->deposit_amount);
        $this->assertSame(800000.0, (float) Payment::firstOrFail()->amount);
    }

    public function test_a_deposit_that_is_not_a_multiple_of_the_rent_is_refused(): void
    {
        $this->enter(['deposit_amount' => '150000', 'entry_months' => '10'])->assertSessionHasErrors('deposit_amount');
        $this->assertSame(0, Lease::count());

        $this->enter(['deposit_amount' => '300000', 'entry_months' => '10'])->assertSessionHasNoErrors();
        $lease = Lease::firstOrFail();
        $this->assertSame(3, $lease->deposit_months);
        $this->assertSame(700000.0, (float) Payment::firstOrFail()->amount);
    }

    public function test_what_goes_to_rent_is_always_a_whole_number_of_months(): void
    {
        foreach ([[10, 2], [4, 3], [6, 1], [12, 0]] as $i => [$entry, $deposit]) {
            $unit = Unit::create(['property_id' => $this->unit->property_id, 'number' => 'M'.$i, 'type' => 'room', 'rent' => 35000]);

            $this->post(route('leases.store', $unit), [
                'tenant_mode' => 'new', 'tenant' => ['name' => 'Client '.$i], 'start_date' => '2026-11-10', 'first_period' => '2026-11',
                'due_day' => '10', 'rent' => '35000', 'deposit_enabled' => '1', 'deposit_months' => (string) $deposit,
                'deposit_amount' => '1234', 'entry_months' => (string) $entry, 'deposit_paid_at' => '2026-11-10',
            ])->assertSessionHasNoErrors();

            $lease = Lease::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame($deposit * 35000.0, (float) $lease->deposit_amount);
            $this->assertSame(($entry - $deposit) * 35000.0, (float) Payment::where('lease_id', $lease->id)->sum('amount'));
            $this->assertSame(0, Invoice::where('lease_id', $lease->id)->whereColumn('paid_amount', '>', '0')->whereColumn('paid_amount', '<', 'amount')->count());
        }
    }

    public function test_the_deposit_amount_can_only_change_to_a_multiple_on_the_lease(): void
    {
        $this->enter(['deposit_enabled' => '1', 'deposit_months' => '2']);
        $lease = Lease::firstOrFail();
        $base = ['due_day' => '10', 'rent' => '100000', 'deposit_paid_at' => '2026-11-10'];

        $this->put(route('leases.update', $lease), $base + ['deposit_amount' => '150000'])->assertSessionHasErrors('deposit_amount');
        $this->assertSame(200000.0, (float) $lease->fresh()->deposit_amount);

        $this->put(route('leases.update', $lease), $base + ['deposit_amount' => '300000'])->assertSessionHasNoErrors();
        $this->assertSame(3, $lease->fresh()->deposit_months);

        $this->put(route('leases.update', $lease), $base + ['deposit_amount' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, $lease->fresh()->deposit_months);
    }

    public function test_an_unchanged_deposit_stays_valid_after_a_rent_change(): void
    {
        $this->enter(['deposit_enabled' => '1', 'deposit_months' => '2']);
        $lease = Lease::firstOrFail();

        $this->put(route('leases.update', $lease), ['due_day' => '10', 'rent' => '120000', 'deposit_amount' => '200000', 'deposit_months' => '2'])->assertSessionHasNoErrors();

        $this->assertSame(200000.0, (float) $lease->fresh()->deposit_amount);
        $this->assertSame(120000.0, (float) $lease->fresh()->rent);
    }

    public function test_the_deposit_can_be_disabled_and_everything_goes_to_rent(): void
    {
        $this->enter(['deposit_enabled' => 0, 'deposit_months' => 2, 'deposit_amount' => 200000, 'entry_months' => 3])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $this->assertSame(0.0, (float) $lease->deposit_amount);
        $this->assertSame(0, (int) $lease->deposit_months);
        $this->assertNull($lease->deposit_paid_at);
        $this->assertSame(0, $lease->depositMovements()->count());
        $this->assertSame(300000.0, (float) Payment::firstOrFail()->amount);
        $this->assertSame('2026-11-10', Payment::firstOrFail()->paid_at->toDateString());
    }

    public function test_a_payment_lower_than_the_deposit_is_refused_and_creates_nothing(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 2, 'entry_months' => 1])->assertSessionHasErrors('entry_months');

        $this->assertSame(0, Lease::count());
        $this->assertSame(0, Tenant::count());
    }

    public function test_a_payment_equal_to_the_deposit_pays_no_rent(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 2, 'entry_months' => 2])->assertSessionHasNoErrors();

        $this->assertSame(200000.0, (float) Lease::firstOrFail()->deposit_amount);
        $this->assertSame(0, Payment::count());
    }

    public function test_without_entry_payment_only_the_deposit_is_recorded(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 2])->assertSessionHasNoErrors();

        $this->assertSame(200000.0, (float) Lease::firstOrFail()->deposit_amount);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_future_payment_date_is_refused(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 2, 'entry_months' => 10, 'deposit_paid_at' => '2026-12-01'])
            ->assertSessionHasErrors('deposit_paid_at');

        $this->assertSame(0, Lease::count());
    }

    public function test_the_default_comes_from_the_settings_and_is_two_months(): void
    {
        $this->assertSame(2, (int) $this->user->organisation->fresh()->deposit_months);
        $this->get(route('leases.create', $this->unit))->assertOk()
            ->assertSee('name="deposit_months"', false)->assertSee('value="2"', false)->assertSee('value="200000"', false);

        $this->put(route('settings.update'), ['name' => 'Alpha', 'currency' => 'XAF', 'reminder_days' => 3, 'deposit_months' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $this->user->organisation->fresh()->deposit_months);

        $this->put(route('settings.update'), ['name' => 'Alpha', 'currency' => 'XAF', 'reminder_days' => 3, 'deposit_months' => 25])->assertSessionHasErrors('deposit_months');
    }

    public function test_the_months_of_deposit_appear_in_the_messages_summary(): void
    {
        $this->enter(['deposit_enabled' => 1, 'deposit_months' => 2]);

        $this->assertStringContainsString('200 000 XAF (2 mois de loyer)', Lease::firstOrFail()->depositSummary());
    }
}
