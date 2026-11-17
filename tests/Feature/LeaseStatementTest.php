<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\LeaseStatement;
use App\Services\RentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaseStatementTest extends TestCase
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

    private function lease(string $name, string $number = '1', string $start = '2026-09-01', float $rent = 100000): Lease
    {
        $property = Property::firstOrCreate(['name' => 'Immeuble']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => $number, 'type' => 'apartment', 'rent' => $rent]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => $name]), [
            'start_date' => $start, 'due_day' => 1, 'rent' => $rent, 'deposit_amount' => 200000,
        ]);
    }

    private function pay(Lease $lease, float $amount, string $date, string $reference = 'R-1'): void
    {
        app(RentService::class)->recordPayment($lease, $amount, $date, 'Espèces', $reference);
    }

    public function test_statement_covers_the_lease_from_first_to_last_month_with_totals(): void
    {
        $lease = $this->lease('Jean');
        $this->pay($lease, 150000, '2026-09-05');

        $data = app(LeaseStatement::class)->build($lease);

        $this->assertSame('2026-09', $data['from']);
        $this->assertSame('2026-11', $data['to']);
        $this->assertCount(3, $data['invoices']);
        $this->assertSame(300000.0, $data['billed']);
        $this->assertSame(150000.0, $data['paid']);
        $this->assertSame(150000.0, $data['unpaid']);
        $this->assertSame(150000.0, $data['overdue']);
        $this->assertSame($data['billed'] - $data['paid'], $data['unpaid']);
    }

    public function test_page_shows_totals_payments_and_deposit_apart(): void
    {
        $lease = $this->lease('Jean');
        $this->pay($lease, 150000, '2026-09-05', 'VIR-77');

        $this->get(route('leases.statement', $lease))->assertOk()
            ->assertSee('État du bail')->assertSee('Jean')
            ->assertSee('Total versé')->assertSee('150 000 XAF')
            ->assertSee('Total des impayés')->assertSee('Total facturé')->assertSee('300 000 XAF')
            ->assertSee('VIR-77')->assertSee('septembre 2026, octobre 2026')
            ->assertSee('Caution versée')->assertSee('Encore détenue : 200 000 XAF');
    }

    public function test_advance_counts_future_months_as_paid(): void
    {
        $lease = $this->lease('Jean', '1', '2026-11-01');
        $this->pay($lease, 300000, '2026-11-01');

        $data = app(LeaseStatement::class)->build($lease);

        $this->assertSame('2027-01', $data['to']);
        $this->assertSame(300000.0, $data['paid']);
        $this->assertSame(0.0, $data['unpaid']);
        $this->assertSame(300000.0, $data['payments']->first()->amount);
    }

    public function test_period_limits_months_and_only_counts_the_share_of_a_payment(): void
    {
        $lease = $this->lease('Jean');
        $this->pay($lease, 150000, '2026-09-05');

        $data = app(LeaseStatement::class)->build($lease, '2026-10', '2026-11');

        $this->assertCount(2, $data['invoices']);
        $this->assertSame(200000.0, $data['billed']);
        $this->assertSame(50000.0, $data['paid']);
        $this->assertSame(150000.0, $data['unpaid']);
        $this->assertSame(50000.0, $data['payments']->sum('amount'));

        $this->get(route('leases.statement', ['lease' => $lease, 'from' => '2026-10', 'to' => '2026-11']))->assertOk()->assertDontSee('septembre 2026 (');
    }

    public function test_reversed_or_invalid_period_is_handled(): void
    {
        $lease = $this->lease('Jean');

        $reversed = app(LeaseStatement::class)->build($lease, '2026-11', '2026-09');
        $this->assertSame(['2026-09', '2026-11'], [$reversed['from'], $reversed['to']]);

        $this->get(route('leases.statement', ['lease' => $lease, 'from' => 'nimporte', 'to' => ['x']]))->assertOk()->assertSee('300 000 XAF');
    }

    public function test_cancelled_payment_disappears_from_the_statement(): void
    {
        $lease = $this->lease('Jean');
        $payment = app(RentService::class)->recordPayment($lease, 100000, '2026-09-05', null, 'ANNULE-1');

        app(RentService::class)->cancelPayment($payment);

        $data = app(LeaseStatement::class)->build($lease);
        $this->assertSame(0.0, $data['paid']);
        $this->assertCount(0, $data['payments']);
        $this->get(route('leases.statement', $lease))->assertDontSee('ANNULE-1');
    }

    public function test_ended_lease_stops_at_its_last_day(): void
    {
        $lease = $this->lease('Jean');
        app(RentService::class)->endLease($lease, ['end_date' => '2026-10-20']);

        $data = app(LeaseStatement::class)->build($lease->fresh());

        $this->assertSame('2026-10', $data['to']);
        $this->assertSame('2026-10-20', $data['lastDay']->toDateString());
        $this->get(route('leases.statement', $lease))->assertOk()->assertSee('Sortie : 20/10/2026');
    }

    public function test_pdf_is_generated(): void
    {
        $lease = $this->lease('Jean Dupont');

        $response = $this->get(route('leases.statement.pdf', $lease))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('etat-du-bail-jean-dupont', $response->headers->get('Content-Disposition'));
    }

    public function test_another_owner_cannot_read_the_statement(): void
    {
        $lease = $this->lease('Jean');

        $this->actingAs($this->owner('Beta'));

        $this->get(route('leases.statement', $lease))->assertNotFound();
        $this->get(route('leases.statement.pdf', $lease))->assertNotFound();
    }

    public function test_unpaid_rows_are_sorted_by_tenant_then_month_by_default(): void
    {
        $zoe = $this->lease('Zoé', '1');
        $this->lease('Amina', '2');
        $this->pay($zoe, 300000, '2026-11-01');
        $this->lease('Zoé Bis', '3', '2026-10-01');

        $table = $this->get(route('unpaid.index'))->assertOk()->original->getData()['invoices'];

        $this->assertSame(
            ['Amina', 'Amina', 'Amina', 'Zoé Bis', 'Zoé Bis'],
            collect($table->items())->map(fn ($invoice) => $invoice->lease->tenant->name)->all(),
        );
        $this->assertSame(['2026-09', '2026-10', '2026-11'], collect($table->items())->take(3)->pluck('period')->all());
    }

    public function test_unpaid_page_summarises_each_tenant_and_filters(): void
    {
        $jean = $this->lease('Jean', '1');
        $awa = $this->lease('Awa', '2', '2026-11-01');
        $this->pay($jean, 150000, '2026-11-01');

        $data = $this->get(route('unpaid.index'))->assertOk()
            ->assertSee(route('leases.statement', $jean), false)->original->getData();

        $rows = collect($data['leases']->items());
        $this->assertSame(['Awa', 'Jean'], $rows->map(fn ($lease) => $lease->tenant->name)->all());
        $this->assertSame(150000.0, (float) $rows[1]->unpaid_total);
        $this->assertSame(2, (int) $rows[1]->unpaid_months);

        $filtered = $this->get(route('unpaid.index', ['tenant_id' => $awa->tenant_id]))->assertOk()->original->getData();
        $this->assertCount(1, $filtered['leases']);
        $this->assertSame(['2026-11'], collect($filtered['invoices']->items())->pluck('period')->all());
    }

    public function test_links_lead_to_the_statement(): void
    {
        $lease = $this->lease('Jean');

        $this->get(route('leases.show', $lease))->assertSee(route('leases.statement', $lease), false);
        $this->get(route('tenants.show', $lease->tenant_id))->assertSee(route('leases.statement', $lease), false);
    }
}
