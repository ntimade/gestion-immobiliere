<?php

namespace Tests\Feature;

use App\Models\Invoice;
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

class NoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $organisation = Organisation::create(['name' => 'Alpha', 'code' => 'ALP-001', 'currency' => 'XAF', 'phone' => '+237695566898']);
        $this->user = $organisation->users()->create(['name' => 'P', 'email' => 'alpha@t.test', 'password' => 'secret-pass-1']);
        $this->actingAs($this->user);
        $property = Property::create(['name' => 'Immeuble', 'address' => 'Rue 12, Bastos']);
        $this->unit = Unit::create(['property_id' => $property->id, 'number' => 'A1', 'type' => 'apartment', 'rent' => 100000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lease(): Lease
    {
        $lease = app(RentService::class)->startLease($this->unit, Tenant::create(['name' => 'Jean', 'gender' => 'M', 'phone' => '+237690000001']), [
            'start_date' => '2026-09-01', 'due_day' => '1', 'rent' => '100000', 'deposit_amount' => '200000', 'deposit_months' => '2', 'deposit_paid_at' => '2026-09-01',
        ]);
        app(RentService::class)->recordPayment($lease, 300000, '2026-11-01', 'Espèces', 'R');

        return $lease->fresh();
    }

    private function notice(Lease $lease, string $end = '2026-12-10')
    {
        return $this->post(route('leases.notice', $lease), ['notice_given_on' => '2026-11-10', 'notice_end_date' => $end]);
    }

    private function messageText(string $url): string
    {
        $location = $this->post($url)->assertRedirect()->headers->get('Location');

        return rawurldecode((string) preg_replace('/^.*?(text|body)=/', '', $location));
    }

    public function test_the_notice_stops_billing_after_the_departure_month(): void
    {
        $lease = $this->lease();
        $this->notice($lease)->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertTrue($fresh->onNotice());
        $this->assertNull($fresh->end_date);

        // Décembre est encore facturé, janvier jamais.
        Carbon::setTestNow('2026-11-29 09:00:00');
        app(RentService::class)->syncOrganisation($this->user->organisation->fresh());
        $this->assertTrue(Invoice::where('lease_id', $lease->id)->where('period', '2026-12')->exists());

        Carbon::setTestNow('2027-02-10 09:00:00');
        app(RentService::class)->syncOrganisation($this->user->organisation->fresh());
        $this->assertSame('2026-12', Invoice::where('lease_id', $lease->id)->max('period'));
        // Décembre reste dû ; après lui, plus aucune échéance.
        $this->assertSame('2026-12-01', $fresh->fresh()->nextDueDate()->toDateString());
        app(RentService::class)->recordPayment($fresh->fresh(), 100000, '2027-02-10', 'Espèces', 'DEC');
        $this->assertNull($fresh->fresh()->nextDueDate());
    }

    public function test_the_unit_is_free_the_day_after_the_departure(): void
    {
        $lease = $this->lease();
        $this->notice($lease);

        $this->assertTrue($this->unit->fresh()->acceptsNewTenant());
        $this->get(route('units.index'))->assertOk()->assertSee('Préavis · libre le 11/12/2026');
        $this->assertSame(1, Unit::occupied()->count());

        Carbon::setTestNow('2026-12-11 09:00:00');
        $this->assertSame(0, Unit::occupied()->count());
        $this->assertNull($this->unit->fresh()->currentLease);
        $this->assertTrue($lease->fresh()->exitToFinalize());
    }

    public function test_the_next_tenant_can_be_booked_from_the_day_after(): void
    {
        $lease = $this->lease();
        $this->notice($lease);

        $this->get(route('leases.create', $this->unit))->assertOk()->assertSee('à partir du 11/12/2026')->assertSee('value="2026-12-11"', false);

        $base = ['tenant_mode' => 'new', 'tenant' => ['name' => 'Suivant'], 'due_day' => '11', 'rent' => '100000', 'deposit_enabled' => '0'];
        $this->post(route('leases.store', $this->unit), $base + ['start_date' => '2026-12-10', 'first_period' => '2026-12'])->assertSessionHasErrors('start_date');
        $this->post(route('leases.store', $this->unit), $base + ['start_date' => '2026-12-11', 'first_period' => '2026-12'])->assertSessionHasNoErrors();

        $this->assertSame(2, Lease::where('unit_id', $this->unit->id)->count());
        // Le préavis ne peut plus être annulé, ni le départ repoussé au-delà de l'entrée suivante.
        $this->post(route('leases.notice.cancel', $lease))->assertSessionHasErrors('notice_end_date');
    }

    public function test_an_occupied_unit_without_notice_still_refuses_a_new_tenant(): void
    {
        $this->lease();

        $this->get(route('leases.create', $this->unit))->assertRedirect(route('units.show', $this->unit));
        $this->assertFalse($this->unit->fresh()->acceptsNewTenant());
    }

    public function test_the_notice_is_validated(): void
    {
        $lease = $this->lease();

        $this->post(route('leases.notice', $lease), ['notice_given_on' => '2026-11-20', 'notice_end_date' => '2026-12-10'])->assertSessionHasErrors('notice_given_on');
        $this->post(route('leases.notice', $lease), ['notice_given_on' => '2026-11-10', 'notice_end_date' => '2026-11-01'])->assertSessionHasErrors('notice_end_date');
        $this->assertFalse($lease->fresh()->onNotice());
    }

    public function test_cancelling_the_notice_resumes_billing(): void
    {
        $lease = $this->lease();
        $this->notice($lease);
        $this->post(route('leases.notice.cancel', $lease))->assertSessionHasNoErrors();

        $this->assertFalse($lease->fresh()->onNotice());
        Carbon::setTestNow('2027-02-10 09:00:00');
        app(RentService::class)->syncOrganisation($this->user->organisation->fresh());
        $this->assertSame('2027-02', Invoice::where('lease_id', $lease->id)->max('period'));
    }

    public function test_months_already_paid_after_the_departure_are_refunded_at_the_exit(): void
    {
        $lease = $this->lease();
        // Avance : novembre à février payés.
        app(RentService::class)->recordPayment($lease->fresh(), 300000, '2026-11-05', 'Espèces', 'AVANCE');
        $this->notice($lease)->assertSessionHasNoErrors();

        $this->assertSame('10/12/2026', $lease->fresh()->paidThrough()->format('d/m/Y'));

        $this->post(route('leases.end', $lease), ['end_date' => '2026-12-10', 'deposit_mode' => 'complete'])->assertSessionHasNoErrors();
        $this->assertSame(200000.0, (float) $lease->fresh()->rentRefunds()->sum('amount'));
    }

    public function test_the_inspection_prefills_the_exit_and_the_deposit_waits_for_it(): void
    {
        $lease = $this->lease();
        $this->notice($lease);
        $this->assertSame(200000.0, $lease->fresh()->depositHeld());

        $this->post(route('leases.inspection', $lease), [
            'inspection_at' => '2026-12-03T10:00', 'inspection_report' => 'Peinture du salon abîmée', 'inspection_cost' => '50000',
        ])->assertSessionHasNoErrors();

        $fresh = $lease->fresh();
        $this->assertSame('2026-12-03 10:00', $fresh->inspection_at->format('Y-m-d H:i'));

        $this->get(route('leases.exit', $lease))->assertOk()
            ->assertSee('value="2026-12-10"', false)
            ->assertSee('value="partial" checked', false)
            ->assertSee('value="150000"', false)
            ->assertSee('Peinture du salon abîmée');
    }

    public function test_the_inspection_needs_a_notice(): void
    {
        $lease = $this->lease();

        $this->post(route('leases.inspection', $lease), ['inspection_at' => '2026-12-03T10:00'])->assertSessionHas('error');
        $this->assertNull($lease->fresh()->inspection_at);
    }

    public function test_the_notice_and_inspection_messages(): void
    {
        $lease = $this->lease();
        $this->notice($lease);

        $text = $this->messageText(route('messages.notice', [$lease, 'whatsapp']));
        $this->assertStringContainsString('CONFIRMATION DE PRÉAVIS', $text);
        $this->assertStringContainsString('le 10/11/2026', $text);
        $this->assertStringContainsString('Date de départ : *10/12/2026*', $text);
        $this->assertStringContainsString('Dernier mois facturé : décembre 2026', $text);
        $this->assertStringContainsString('Reste à payer avant le départ : '.money(100000), $text);
        $this->assertStringContainsString('remboursée après l\'état des lieux', $text);
        $this->assertNotNull($lease->fresh()->notice_opened_at);

        $sms = $this->messageText(route('messages.notice', [$lease, 'sms']));
        $this->assertSame(Str::ascii($sms), $sms);
        $this->assertStringContainsString('depart le 10/12/2026', $sms);
        $this->assertLessThanOrEqual(160, strlen($sms));

        // Pas d'invitation avant d'avoir fixé la visite.
        $this->post(route('messages.inspection', [$lease, 'whatsapp']))->assertSessionHas('error');

        $this->post(route('leases.inspection', $lease), ['inspection_at' => '2026-12-03T10:00']);
        $text = $this->messageText(route('messages.inspection', [$lease, 'whatsapp']));
        $this->assertStringContainsString('ÉTAT DES LIEUX DE SORTIE', $text);
        $this->assertStringContainsString('jeudi 3 décembre 2026 à 10h00', $text);
        $this->assertStringContainsString('Rue 12, Bastos', $text);
        $this->assertStringContainsString('prévoir les clés', $text);

        $sms = $this->messageText(route('messages.inspection', [$lease, 'sms']));
        $this->assertStringContainsString('03/12/2026 a 10h00', $sms);
        $this->assertLessThanOrEqual(160, strlen($sms));
    }

    public function test_the_tenant_page_leads_to_the_notice(): void
    {
        $lease = $this->lease();

        $this->get(route('tenants.show', $lease->tenant))->assertOk()
            ->assertSee('Ouvrir le bail')
            ->assertSee(route('leases.show', $lease).'#preavis', false)
            ->assertSee('Préavis / sortie');

        $this->notice($lease);
        $this->get(route('tenants.show', $lease->tenant))->assertOk()->assertSee('Préavis : départ le 10/12/2026');

        $this->post(route('leases.end', $lease), ['end_date' => '2026-12-10', 'deposit_mode' => 'complete']);
        $this->get(route('tenants.show', $lease->tenant))->assertOk()->assertDontSee('Préavis / sortie')->assertSee('10/12/2026');
    }

    public function test_the_lease_page_and_dashboard_show_the_notice(): void
    {
        $lease = $this->lease();
        $this->get(route('leases.show', $lease))->assertOk()->assertSee('Enregistrer le préavis');

        $this->notice($lease);
        $this->get(route('leases.show', $lease))->assertOk()->assertSee('Préavis : départ le 10/12/2026')->assertSee('Confirmer sur WhatsApp')->assertSee('État des lieux de sortie');
        $this->get(route('dashboard'))->assertOk()->assertSee('Préavis en cours')->assertSee('À planifier');

        Carbon::setTestNow('2026-12-12 09:00:00');
        $this->get(route('dashboard'))->assertOk()->assertSee('Finaliser la sortie');
    }
}
