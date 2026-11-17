<?php

namespace Tests\Feature;

use App\Models\DepositMovement;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeaseExitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 09:00:00');
        $organisation = Organisation::create(['name' => 'Alpha', 'code' => 'ALP-001', 'currency' => 'XAF']);
        $this->user = $organisation->users()->create(['name' => 'P', 'email' => 'alpha@t.test', 'password' => 'secret-pass-1']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lease(float $deposit = 200000): Lease
    {
        $property = Property::create(['name' => 'Immeuble']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'rent' => 100000]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Jean', 'gender' => 'M', 'phone' => '+237690000001']), [
            'start_date' => '2026-11-01', 'due_day' => '1', 'rent' => '100000', 'deposit_amount' => (string) $deposit, 'deposit_months' => '2', 'deposit_paid_at' => '2026-11-01',
        ]);
    }

    // Toutes les valeurs en texte, comme un navigateur.
    private function leave(Lease $lease, array $data)
    {
        return $this->post(route('leases.end', $lease), $data + ['end_date' => '2026-11-20', 'deposit_refunded_on' => '2026-11-22']);
    }

    private function messageText(string $url): string
    {
        $location = $this->post($url)->assertRedirect()->headers->get('Location');

        return rawurldecode((string) preg_replace('/^.*?(text|body)=/', '', $location));
    }

    public function test_a_complete_refund_needs_no_justification(): void
    {
        $lease = $this->lease();

        $this->leave($lease, ['deposit_mode' => 'complete'])->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $lease->fresh();
        $this->assertSame(200000.0, (float) $fresh->deposit_refunded);
        $this->assertSame(0.0, (float) $fresh->deposit_retained);
        $this->assertSame(0.0, $fresh->depositHeld());
        $this->assertNull($fresh->deposit_note);
        $this->assertSame('22/11/2026', $fresh->depositRefundedOn()->format('d/m/Y'));
        $this->assertSame([DepositMovement::PAID, DepositMovement::REFUNDED], $fresh->depositMovements()->pluck('type')->all());
    }

    public function test_a_complete_refund_ignores_stale_partial_values(): void
    {
        $lease = $this->lease();

        $this->leave($lease, ['deposit_mode' => 'complete', 'deposit_refunded' => '1000', 'deposit_note' => 'Oubli'])->assertSessionHasNoErrors();

        $this->assertSame(200000.0, (float) $lease->fresh()->deposit_refunded);
        $this->assertNull($lease->fresh()->deposit_note);
    }

    public function test_a_partial_refund_must_be_justified(): void
    {
        $lease = $this->lease();

        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '150000'])->assertSessionHasErrors('deposit_note');
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '150000', 'deposit_note' => '   '])->assertSessionHasErrors('deposit_note');

        $fresh = $lease->fresh();
        $this->assertNull($fresh->end_date);
        $this->assertSame(1, $fresh->depositMovements()->count());
    }

    public function test_a_partial_refund_records_what_is_kept_and_why(): void
    {
        $lease = $this->lease();

        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '150000', 'deposit_note' => 'Peinture à refaire'])->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertSame(150000.0, (float) $fresh->deposit_refunded);
        $this->assertSame(50000.0, (float) $fresh->deposit_retained);
        $this->assertSame(0.0, $fresh->depositHeld());
        $this->assertSame('Peinture à refaire', $fresh->deposit_note);

        $retained = $fresh->depositMovements()->where('type', DepositMovement::RETAINED)->first();
        $this->assertSame(-50000.0, (float) $retained->amount);
        $this->assertSame('Peinture à refaire', $retained->note);
        $this->assertSame('22/11/2026', $fresh->depositMovements()->where('type', DepositMovement::REFUNDED)->first()->happened_on->format('d/m/Y'));

        foreach ([route('leases.show', $lease), route('leases.statement', $lease), route('tenants.history', $lease->tenant_id)] as $url) {
            $this->get($url)->assertOk()->assertSee('Peinture à refaire')->assertSee('Retenue sur la caution')->assertSee('Caution rendue');
        }
    }

    public function test_nothing_refunded_is_a_partial_case_with_a_justification(): void
    {
        $lease = $this->lease();

        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '0'])->assertSessionHasErrors('deposit_note');
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '0', 'deposit_note' => 'Dégâts importants'])->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertSame(0.0, (float) $fresh->deposit_refunded);
        $this->assertSame(200000.0, (float) $fresh->deposit_retained);
        $this->assertNull($fresh->depositRefundedOn());
        $this->assertSame(0, $fresh->depositMovements()->where('type', DepositMovement::REFUNDED)->count());
    }

    public function test_a_partial_refund_must_be_lower_than_the_deposit(): void
    {
        $lease = $this->lease();

        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '200000', 'deposit_note' => 'X'])->assertSessionHasErrors('deposit_refunded');
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '250000', 'deposit_note' => 'X'])->assertSessionHasErrors('deposit_refunded');
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_note' => 'X'])->assertSessionHasErrors('deposit_refunded');

        $this->assertNull($lease->fresh()->end_date);
    }

    public function test_the_service_refuses_an_unjustified_retention_whatever_the_screen(): void
    {
        $lease = $this->lease();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(RentService::class)->endLease($lease, ['end_date' => '2026-11-20', 'deposit_retained' => 50000, 'deposit_refunded' => 150000]);
    }

    public function test_a_lease_without_deposit_leaves_without_any_question(): void
    {
        $lease = $this->lease(0);

        $this->get(route('leases.exit', $lease))->assertOk()->assertSee('rien à rembourser')->assertDontSee('name="deposit_mode"', false);
        $this->post(route('leases.end', $lease), ['end_date' => '2026-11-20'])->assertSessionHasNoErrors();

        $this->assertNotNull($lease->fresh()->end_date);
        $this->assertSame(0, $lease->fresh()->depositMovements()->count());
    }

    public function test_the_exit_screen_offers_both_choices(): void
    {
        $lease = $this->lease();

        $this->get(route('leases.exit', $lease))->assertOk()
            ->assertSee('Remboursement complet')->assertSee('Remboursement partiel')
            ->assertSee('Justification de la retenue')->assertSee('Date du remboursement');
    }

    public function test_an_unpaid_debt_stays_due_after_a_retention(): void
    {
        $lease = $this->lease();
        $this->get(route('leases.exit', $lease))->assertOk()->assertSee('même si vous retenez');

        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '100000', 'deposit_note' => 'Loyer impayé'])->assertSessionHasNoErrors();

        $this->assertSame(100000.0, $lease->fresh()->unpaidTotal());
    }

    public function test_cancelling_the_exit_restores_the_deposit_and_keeps_the_trace(): void
    {
        $lease = $this->lease();
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '150000', 'deposit_note' => 'Peinture'])->assertSessionHasNoErrors();
        $this->post(route('messages.exit', [$lease, 'whatsapp']));

        $this->post(route('leases.reopen', $lease))->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertNull($fresh->end_date);
        $this->assertNull($fresh->exit_notice_opened_at);
        $this->assertSame(200000.0, $fresh->depositHeld());
        $this->assertSame('Peinture', $fresh->depositMovements()->where('type', DepositMovement::RETAINED)->value('note'));
        $this->assertSame(DepositMovement::EXIT_CANCELLED, $fresh->depositMovements()->get()->last()->type);
    }

    public function test_the_statement_is_sent_on_whatsapp_and_sms_after_a_partial_refund(): void
    {
        $lease = $this->lease();
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '150000', 'deposit_note' => 'Peinture à refaire']);

        $text = $this->messageText(route('messages.exit', [$lease, 'whatsapp']));
        $this->assertStringContainsString('DÉCOMPTE DE SORTIE', $text);
        $this->assertStringContainsString('Caution versée : '.money(200000), $text);
        $this->assertStringContainsString('Caution remboursée : '.money(150000).', le 22/11/2026', $text);
        $this->assertStringContainsString('Retenue : '.money(50000), $text);
        $this->assertStringContainsString('Justification : Peinture à refaire', $text);
        $this->assertStringContainsString('Loyers encore dus : '.money(100000), $text);
        $this->assertNotNull($lease->fresh()->exit_notice_opened_at);

        $sms = $this->messageText(route('messages.exit', [$lease, 'sms']));
        $this->assertSame(Str::ascii($sms), $sms);
        $this->assertStringContainsString('Caution : '.Str::ascii(money(150000)).' rendus, '.Str::ascii(money(50000)).' retenus (Peinture a refaire)', $sms);
        $this->assertLessThanOrEqual(160, strlen($sms));
    }

    public function test_the_statement_after_a_complete_refund_shows_no_retention(): void
    {
        $lease = $this->lease();
        app(RentService::class)->recordPayment($lease, 100000, '2026-11-01', 'Espèces', 'R');
        $this->leave($lease, ['deposit_mode' => 'complete']);

        $text = $this->messageText(route('messages.exit', [$lease, 'whatsapp']));
        $this->assertStringContainsString('Caution remboursée : '.money(200000), $text);
        $this->assertStringNotContainsString('Retenue', $text);
        $this->assertStringNotContainsString('Loyers encore dus', $text);

        $this->get(route('leases.show', $lease))->assertOk()->assertSee('Décompte sur WhatsApp')->assertSee('Décompte par SMS');
    }

    public function test_no_statement_before_the_exit_or_from_another_company(): void
    {
        $lease = $this->lease();

        $this->post(route('messages.exit', [$lease, 'whatsapp']))->assertSessionHas('error');
        $this->get(route('leases.show', $lease))->assertDontSee('Décompte sur WhatsApp');

        $this->leave($lease, ['deposit_mode' => 'complete']);
        $other = Organisation::create(['name' => 'Beta', 'code' => 'BET-001', 'currency' => 'XAF']);
        $this->actingAs($other->users()->create(['name' => 'B', 'email' => 'beta@t.test', 'password' => 'secret-pass-1']));

        $this->post(route('messages.exit', [$lease, 'whatsapp']))->assertNotFound();
    }

    private function quarterly(): Lease
    {
        $property = Property::create(['name' => 'Trimestre']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => 'T1', 'type' => 'apartment', 'rent' => 100000]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Nina', 'gender' => 'F', 'phone' => '+237690000002']), [
            'start_date' => '2026-11-01', 'due_day' => '1', 'billing_months' => '3', 'rent' => '100000', 'deposit_amount' => '200000', 'deposit_months' => '2', 'deposit_paid_at' => '2026-11-01',
        ]);
    }

    public function test_leaving_in_the_middle_of_a_paid_cycle_refunds_the_months_after_the_exit(): void
    {
        $lease = $this->quarterly();
        $payment = app(RentService::class)->recordPayment($lease, 300000, '2026-11-01', 'Espèces', 'TRIMESTRE');

        $this->leave($lease, ['end_date' => '2026-11-30', 'deposit_mode' => 'complete'])->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertNotNull($fresh->end_date);
        // Le paiement d'origine reste tel quel ; décembre et janvier sont rendus à part.
        $this->assertSame(300000.0, (float) $payment->fresh()->amount);
        $this->assertSame(['2026-11'], $fresh->invoices()->pluck('period')->all());
        $this->assertSame(0.0, $fresh->unpaidTotal());
        $refund = $fresh->rentRefunds()->first();
        $this->assertSame(200000.0, (float) $refund->amount);
        $this->assertSame('2026-11-22', $refund->refunded_on->toDateString());
        $this->assertStringContainsString('décembre 2026, janvier 2027', $refund->note);
        $this->assertSame(100000.0, round((float) $payment->fresh()->allocations()->sum('amount'), 3));

        $this->get(route('leases.show', $lease))->assertOk()->assertSee('Loyers remboursés')->assertSee('décembre 2026, janvier 2027');
        $this->get(route('leases.statement', $lease))->assertOk()->assertSee('Loyers remboursés');

        $text = $this->messageText(route('messages.exit', [$lease, 'whatsapp']));
        $this->assertStringContainsString('Loyers remboursés : '.money(200000).', le 22/11/2026', $text);
        $sms = $this->messageText(route('messages.exit', [$lease, 'sms']));
        $this->assertStringContainsString('Loyers rendus : '.Str::ascii(money(200000)), $sms);
        $this->assertLessThanOrEqual(160, strlen($sms));
    }

    public function test_cancelling_that_exit_brings_the_prepaid_months_back(): void
    {
        $lease = $this->quarterly();
        app(RentService::class)->recordPayment($lease, 300000, '2026-11-01', 'Espèces', 'TRIMESTRE');
        $this->leave($lease, ['end_date' => '2026-11-30', 'deposit_mode' => 'complete']);

        $this->post(route('leases.reopen', $lease))->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertNull($fresh->end_date);
        $this->assertCount(0, $fresh->rentRefunds()->get());
        $this->assertSame(1, \App\Models\RentRefund::where('lease_id', $lease->id)->whereNotNull('cancelled_at')->count());
        $this->assertSame('31/01/2027', $fresh->paidThrough()->format('d/m/Y'));
        $this->assertSame(0.0, $fresh->unpaidTotal());
    }

    public function test_part_of_the_retention_can_settle_unpaid_rent(): void
    {
        $lease = $this->lease();
        $this->assertSame(100000.0, $lease->unpaidTotal());

        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '100000', 'deposit_note' => 'Loyer de novembre impayé', 'deposit_to_rent' => '100000'])->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertSame(0.0, $fresh->unpaidTotal());
        $this->assertSame(100000.0, $fresh->depositToRent());
        $this->assertSame(100000.0, (float) $fresh->deposit_retained);
        $this->assertSame(0.0, $fresh->depositHeld());
        $this->assertStringContainsString('dont '.money(100000).' affectés aux loyers impayés', $fresh->depositMovements()->where('type', DepositMovement::RETAINED)->value('note'));

        $payment = \App\Models\Payment::where('lease_id', $lease->id)->first();
        $this->assertSame(\App\Models\Payment::FROM_DEPOSIT, $payment->source);
        $this->assertSame('Retenue sur caution', $payment->method);

        // Ce n'est pas un paiement du locataire à confirmer.
        $this->get(route('messages.index', ['etat' => 'paiements']))->assertOk()->assertDontSee('Retenue sur caution');
        $this->assertStringContainsString('Dont affectés aux loyers impayés : '.money(100000), $this->messageText(route('messages.exit', [$lease, 'whatsapp'])));
    }

    public function test_the_part_given_to_rent_is_capped(): void
    {
        $lease = $this->lease();

        // Plus que la retenue.
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '150000', 'deposit_note' => 'X', 'deposit_to_rent' => '60000'])->assertSessionHasErrors('deposit_to_rent');
        // Plus que les impayés (100 000).
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '0', 'deposit_note' => 'X', 'deposit_to_rent' => '150000'])->assertSessionHasErrors('deposit_to_rent');
        // Un remboursement complet n'affecte rien.
        $this->leave($lease, ['deposit_mode' => 'complete', 'deposit_to_rent' => '50000'])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $lease->fresh()->depositToRent());
        $this->assertSame(100000.0, $lease->fresh()->unpaidTotal());
    }

    public function test_cancelling_the_exit_takes_back_the_part_given_to_rent(): void
    {
        $lease = $this->lease();
        $this->leave($lease, ['deposit_mode' => 'partial', 'deposit_refunded' => '100000', 'deposit_note' => 'Loyer impayé', 'deposit_to_rent' => '100000']);

        $this->post(route('leases.reopen', $lease))->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertSame(0.0, $fresh->depositToRent());
        $this->assertSame(100000.0, $fresh->unpaidTotal());
        $this->assertSame(200000.0, $fresh->depositHeld());
    }

    public function test_the_exit_screen_offers_the_part_given_to_rent_only_when_rent_is_unpaid(): void
    {
        $lease = $this->lease();
        $this->get(route('leases.exit', $lease))->assertOk()->assertSee('Part de la retenue affectée aux loyers impayés');

        app(RentService::class)->recordPayment($lease, 100000, '2026-11-01', 'Espèces', 'R');
        $this->get(route('leases.exit', $lease))->assertOk()->assertDontSee('Part de la retenue affectée aux loyers impayés');
    }
}
