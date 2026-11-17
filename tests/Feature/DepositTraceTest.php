<?php

namespace Tests\Feature;

use App\Models\DepositMovement;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\LeaseStatement;
use App\Services\LegalNotice;
use App\Services\RentService;
use App\Support\InvoiceMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DepositTraceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $organisation = Organisation::create(['name' => 'Alpha', 'code' => 'ALP-001', 'currency' => 'XAF', 'legal_notice_after' => 1, 'legal_notice_unit' => 'months']);
        $this->user = $organisation->users()->create(['name' => 'Paul Gérant', 'email' => 'alpha@t.test', 'password' => 'secret-pass-1']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lease(float $deposit = 200000, ?string $paidAt = '2026-09-28'): Lease
    {
        $property = Property::firstOrCreate(['name' => 'Immeuble']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'rent' => 100000]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Jean', 'gender' => 'M', 'phone' => '+237690000001']), [
            'start_date' => '2026-10-01', 'due_day' => 1, 'rent' => 100000, 'deposit_amount' => $deposit, 'deposit_paid_at' => $paidAt,
        ]);
    }

    private function balance(Lease $lease): float
    {
        return round((float) $lease->depositMovements()->sum('amount'), 3);
    }

    public function test_every_change_leaves_a_line_and_the_history_matches_what_is_held(): void
    {
        $rent = app(RentService::class);
        $lease = $this->lease();

        $this->assertSame([DepositMovement::PAID], $lease->depositMovements()->pluck('type')->all());
        $this->assertSame($this->user->id, $lease->depositMovements()->first()->user_id);

        $rent->updateLease($lease, ['deposit_amount' => 250000, 'deposit_paid_at' => '2026-09-28']);
        $rent->endLease($lease->fresh(), ['end_date' => '2026-11-30', 'deposit_retained' => 50000, 'deposit_refunded' => 150000, 'deposit_note' => 'Peinture']);
        $this->assertSame(50000.0, $this->balance($lease));
        $this->assertSame($lease->fresh()->depositHeld(), $this->balance($lease));

        $rent->reopenLease($lease->fresh());
        $fresh = $lease->fresh();

        $this->assertSame(
            [DepositMovement::PAID, DepositMovement::ADJUSTED, DepositMovement::RETAINED, DepositMovement::REFUNDED, DepositMovement::EXIT_CANCELLED],
            $fresh->depositMovements()->pluck('type')->all(),
        );
        $this->assertSame('Peinture', $fresh->depositMovements()->where('type', DepositMovement::RETAINED)->value('note'));
        $this->assertSame(250000.0, $fresh->depositHeld());
        $this->assertSame($fresh->depositHeld(), $this->balance($fresh));
    }

    public function test_changing_only_the_payment_date_is_traced_and_nothing_changed_adds_nothing(): void
    {
        $rent = app(RentService::class);
        $lease = $this->lease();

        $rent->updateLease($lease, ['deposit_amount' => 200000, 'deposit_paid_at' => '2026-09-28']);
        $this->assertSame(1, $lease->depositMovements()->count());

        $rent->updateLease($lease->fresh(), ['deposit_amount' => 200000, 'deposit_paid_at' => '2026-09-30']);
        $last = $lease->depositMovements()->get()->last();
        $this->assertSame(DepositMovement::ADJUSTED, $last->type);
        $this->assertStringContainsString('30/09/2026', $last->note);
        $this->assertSame(200000.0, $this->balance($lease));
    }

    public function test_the_deposit_appears_on_the_lease_page_with_its_author(): void
    {
        $lease = $this->lease();

        $this->get(route('leases.show', $lease))->assertOk()
            ->assertSee('Historique de la caution')
            ->assertSee('Caution versée')
            ->assertSee('28/09/2026')
            ->assertSee('Paul Gérant');
    }

    public function test_the_deposit_appears_on_the_statement_and_the_invoice(): void
    {
        $lease = $this->lease();
        $invoice = Invoice::where('lease_id', $lease->id)->orderBy('period')->first();

        $this->get(route('leases.statement', $lease))->assertOk()->assertSee('Caution versée')->assertSee('28/09/2026');
        $this->get(route('leases.statement.pdf', $lease))->assertOk();
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('versée le 28/09/2026', false);
    }

    public function test_every_whatsapp_message_recalls_the_deposit(): void
    {
        $lease = $this->lease();
        $invoice = Invoice::with(['lease.tenant', 'lease.unit.property'])->where('lease_id', $lease->id)->orderBy('period')->first();
        $summary = 'Caution : '.money(200000).', versée le 28/09/2026, encore détenue : '.money(200000);
        $notice = app(LegalNotice::class)->forLease($lease);
        $payment = app(RentService::class)->recordPayment($lease, 10000, '2026-11-09', 'Espèces', 'R-1');

        foreach ([
            InvoiceMessage::whatsapp($invoice),
            InvoiceMessage::reminder($invoice),
            InvoiceMessage::lateNotice($invoice->lease, collect([$invoice])),
            InvoiceMessage::legalNotice($notice->lease, $notice),
            InvoiceMessage::payment($payment->load(['lease.tenant', 'lease.unit.property', 'allocations.invoice'])),
        ] as $message) {
            $this->assertStringContainsString($summary, $message);
        }
    }

    public function test_the_legal_letter_keeps_the_deposit_apart_from_the_debt(): void
    {
        $lease = $this->lease();
        $notice = app(LegalNotice::class)->forLease($lease);

        $html = view('leases.legal-notice-pdf', [
            'notice' => $notice, 'lease' => $notice->lease, 'lastPayment' => null,
            'organisation' => $this->user->organisation, 'logoSrc' => null,
        ])->render();

        $this->assertStringContainsString('Caution versée', $html);
        $this->assertStringContainsString('28/09/2026', $html);
        $this->assertStringContainsString(e('n\'est pas déduite du total dû'), $html);
        $this->assertSame((float) 200000, app(LeaseStatement::class)->build($lease)['lease']->depositHeld());
    }

    public function test_a_fully_refunded_deposit_stays_visible_everywhere(): void
    {
        $lease = $this->lease();
        app(RentService::class)->endLease($lease, ['end_date' => '2026-11-30', 'deposit_refunded' => 200000]);
        $tenant = $lease->tenant;
        $this->assertSame(0.0, $lease->fresh()->depositHeld());

        foreach ([
            route('tenants.history', $tenant),
            route('leases.statement', $lease),
            route('leases.show', $lease),
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('Caution versée')->assertSee('Caution rendue')->assertSee('28/09/2026')->assertSee('30/11/2026');
        }

        foreach ([route('tenants.show', $tenant), route('tenants.index'), route('units.show', $lease->unit)] as $url) {
            $this->get($url)->assertOk()->assertSee('200 000 XAF')->assertSee('rendue : 200 000 XAF');
        }

        $pdf = $this->get(route('tenants.history.pdf', $tenant));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_the_full_history_covers_every_lease_of_the_tenant(): void
    {
        $rent = app(RentService::class);
        $first = $this->lease();
        $rent->endLease($first, ['end_date' => '2026-10-31', 'deposit_retained' => 50000, 'deposit_refunded' => 150000, 'deposit_note' => 'Peinture']);

        $property = Property::firstOrCreate(['name' => 'Immeuble']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => '2', 'type' => 'studio', 'rent' => 80000]);
        $rent->startLease($unit, $first->tenant, ['start_date' => '2026-11-01', 'due_day' => 5, 'rent' => 80000, 'deposit_amount' => 80000, 'deposit_paid_at' => '2026-11-01']);

        $this->get(route('tenants.history', $first->tenant))->assertOk()
            ->assertSee('Historique du locataire')
            ->assertSee('n° 1', false)->assertSee('n° 2', false)
            ->assertSee('Peinture')
            ->assertSee('280 000 XAF')
            ->assertSee('80 000 XAF');
    }

    public function test_the_menu_leads_to_the_history_of_any_tenant_with_the_deposit(): void
    {
        $lease = $this->lease();
        app(RentService::class)->endLease($lease, ['end_date' => '2026-11-30', 'deposit_refunded' => 200000]);

        $this->get(route('dashboard'))->assertSee(route('history.index'), false)->assertSee('Historiques');

        $this->get(route('history.index'))->assertOk()
            ->assertSee('Jean')
            ->assertSee('versée : 200 000 XAF')
            ->assertSee('rendue : 200 000 XAF')
            ->assertSee(route('tenants.history', $lease->tenant_id), false)
            ->assertSee(route('tenants.history.pdf', $lease->tenant_id), false);

        $this->get(route('history.index', ['q' => 'Personne']))->assertOk()->assertDontSee(route('tenants.history', $lease->tenant_id), false);
    }

    public function test_the_list_of_histories_stays_within_the_company(): void
    {
        $this->lease();
        $other = Organisation::create(['name' => 'Beta', 'code' => 'BET-001', 'currency' => 'XAF']);
        $this->actingAs($other->users()->create(['name' => 'B', 'email' => 'beta@t.test', 'password' => 'secret-pass-1']));

        $this->get(route('history.index'))->assertOk()->assertDontSee('Jean');
    }

    public function test_another_company_cannot_open_the_history(): void
    {
        $lease = $this->lease();
        $other = Organisation::create(['name' => 'Beta', 'code' => 'BET-001', 'currency' => 'XAF']);
        $this->actingAs($other->users()->create(['name' => 'B', 'email' => 'beta@t.test', 'password' => 'secret-pass-1']));

        $this->get(route('tenants.history', $lease->tenant_id))->assertNotFound();
        $this->get(route('tenants.history.pdf', $lease->tenant_id))->assertNotFound();
    }

    public function test_a_lease_without_deposit_says_so(): void
    {
        $lease = $this->lease(0, null);

        $this->assertSame('Caution : aucune enregistrée', $lease->depositSummary());
        $this->assertSame(0, $lease->depositMovements()->count());
        $this->get(route('leases.show', $lease))->assertOk()->assertSee('Aucune caution enregistrée sur ce bail.');
    }
}
