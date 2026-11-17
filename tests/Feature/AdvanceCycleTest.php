<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceMessageController;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use App\Support\InvoiceMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdvanceCycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $organisation = Organisation::create(['name' => 'Alpha', 'code' => 'ALP-001', 'currency' => 'XAF']);
        $this->user = $organisation->users()->create(['name' => 'P', 'email' => 'alpha@t.test', 'password' => 'secret-pass-1']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lease(int $dueDay = 1): Lease
    {
        $property = Property::create(['name' => 'Immeuble']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'rent' => 100000]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Jean', 'gender' => 'M', 'phone' => '+237690000001']), [
            'start_date' => '2026-11-01', 'due_day' => $dueDay, 'rent' => 100000, 'deposit_amount' => 200000, 'deposit_months' => 2, 'deposit_paid_at' => '2026-11-01',
        ]);
    }

    private function goTo(string $date): void
    {
        Carbon::setTestNow($date.' 09:00:00');
        app(RentService::class)->syncOrganisation($this->user->organisation->fresh());
    }

    private function pay(Lease $lease, float $amount, string $date)
    {
        return app(RentService::class)->recordPayment($lease->fresh(), $amount, $date, 'Espèces', 'R');
    }

    public function test_after_an_advance_the_rent_goes_back_to_one_month_at_a_time(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 600000, '2026-11-01');

        $this->assertSame('30/04/2027', $lease->paidThrough()->format('d/m/Y'));
        $this->assertSame('01/05/2027', $lease->nextDueDate()->format('d/m/Y'));

        // Pendant l'avance, rien n'est demandé.
        $this->goTo('2027-04-20');
        $this->assertSame(0.0, $lease->fresh()->unpaidTotal());
        $this->assertSame(0, InvoiceMessageController::remindersToSend()->count());

        // À la fin de l'avance : un seul mois, au tarif mensuel, et pas une nouvelle avance.
        $this->goTo('2027-04-29');
        $this->assertSame(100000.0, $lease->fresh()->unpaidTotal());
        $this->assertSame(['2027-05'], Invoice::where('lease_id', $lease->id)->unpaid()->pluck('period')->all());
        $this->assertFalse(Invoice::where('lease_id', $lease->id)->where('period', '2027-06')->exists());
        $this->assertSame(1, InvoiceMessageController::remindersToSend()->count());

        // Paiement d'un seul mois accepté : le cycle continue.
        $this->pay($lease, 100000, '2027-05-01');
        $this->assertSame('31/05/2027', $lease->fresh()->paidThrough()->format('d/m/Y'));
        $this->assertSame(0.0, $lease->fresh()->unpaidTotal());
    }

    public function test_a_new_advance_moves_the_paid_through_date(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 600000, '2026-11-01');

        $this->goTo('2027-06-02');
        $this->assertSame(200000.0, $lease->fresh()->unpaidTotal());

        $payment = $this->pay($lease, 400000, '2027-06-02');

        $this->assertSame('31/08/2027', $lease->fresh()->paidThrough()->format('d/m/Y'));
        $this->assertSame('01/09/2027', $lease->fresh()->nextDueDate()->format('d/m/Y'));

        $payment->load(['lease.tenant', 'lease.unit.property', 'allocations.invoice']);
        $this->assertStringContainsString('Loyer payé jusqu\'au *31/08/2027*', InvoiceMessage::payment($payment));
        $this->assertStringContainsString('Paye jusqu\'au 31/08/2027', InvoiceMessage::paymentSms($payment));
    }

    public function test_the_date_follows_the_due_day_of_the_tenant(): void
    {
        $lease = $this->lease(10);
        $this->pay($lease, 300000, '2026-11-01');

        // Novembre, décembre et janvier réglés : couverts jusqu'à la veille de l'échéance de février.
        $this->assertSame('09/02/2027', $lease->paidThrough()->format('d/m/Y'));
    }

    public function test_a_partly_paid_month_is_not_counted_as_covered(): void
    {
        $lease = $this->lease();
        $this->assertNull($lease->paidThrough());

        $this->pay($lease, 50000, '2026-11-01');
        $this->assertNull($lease->fresh()->paidThrough());
        $this->assertSame('01/11/2026', $lease->fresh()->nextDueDate()->format('d/m/Y'));

        $this->pay($lease, 100000, '2026-11-02');
        $this->assertSame('30/11/2026', $lease->fresh()->paidThrough()->format('d/m/Y'));
        $this->assertSame('01/12/2026', $lease->fresh()->nextDueDate()->format('d/m/Y'));
    }

    public function test_the_deposit_is_only_paid_at_entry(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 600000, '2026-11-01');
        $this->goTo('2027-06-02');
        $this->pay($lease, 400000, '2027-06-02');

        $fresh = $lease->fresh();
        $this->assertSame(200000.0, (float) $fresh->deposit_amount);
        $this->assertSame(1, $fresh->depositMovements()->count());
        $this->assertSame(200000.0, round((float) $fresh->depositMovements()->sum('amount'), 3));
    }

    public function test_the_date_is_shown_after_payment_and_on_the_documents(): void
    {
        $lease = $this->lease();

        $this->post(route('payments.store', $lease), ['amount' => 600000, 'paid_at' => '2026-11-01'])
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'jusqu\'au 30/04/2027'));

        $this->get(route('leases.show', $lease))->assertOk()->assertSee('30/04/2027')->assertSee('01/05/2027')
            ->assertSee('La caution se verse une seule fois');
        $this->get(route('leases.statement', $lease))->assertOk()->assertSee('30/04/2027');
        $this->get(route('tenants.history', $lease->tenant_id))->assertOk()->assertSee('30/04/2027');
    }

    public function test_an_ended_lease_is_not_covered_after_its_exit(): void
    {
        $lease = $this->lease();
        $this->pay($lease, 100000, '2026-11-01');
        app(RentService::class)->endLease($lease->fresh(), ['end_date' => '2026-11-15', 'deposit_refunded' => 200000]);

        $this->assertSame('15/11/2026', $lease->fresh()->paidThrough()->format('d/m/Y'));
        $this->assertNull($lease->fresh()->nextDueDate());
    }
}
