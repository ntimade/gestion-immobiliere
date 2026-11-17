<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\LegalNotice;
use App\Services\RentService;
use App\Support\InvoiceMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegalNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->user = $this->owner('Alpha');
        $this->actingAs($this->user);
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

    private function delay(?int $after, string $unit = 'months'): void
    {
        $this->user->organisation->update(['legal_notice_after' => $after, 'legal_notice_unit' => $unit]);
    }

    private function lease(string $start = '2026-09-01', ?string $phone = '+237690000001', string $number = '1'): Lease
    {
        $property = Property::firstOrCreate(['name' => 'Immeuble']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => $number, 'type' => 'apartment', 'rent' => 100000]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Jean', 'gender' => 'M', 'phone' => $phone]), [
            'start_date' => $start, 'due_day' => 1, 'rent' => 100000, 'deposit_amount' => 0,
        ]);
    }

    public function test_nothing_is_proposed_until_the_owner_sets_a_delay(): void
    {
        $lease = $this->lease();

        $this->assertCount(0, app(LegalNotice::class)->rows());
        $this->get(route('messages.index', ['etat' => 'procedure']))->assertOk()->assertSee('Régler le délai');
        $this->post(route('messages.legal', [$lease, 'whatsapp']))->assertRedirect();
        $this->assertNull($lease->fresh()->legal_notice_opened_at);
    }

    public function test_settings_store_the_delay_and_can_clear_it(): void
    {
        $base = ['name' => 'Alpha', 'currency' => 'XAF', 'reminder_days' => 3];

        $this->put(route('settings.update'), $base + ['legal_notice_after' => 45, 'legal_notice_unit' => 'days'])->assertSessionHasNoErrors();
        $organisation = $this->user->organisation->fresh();
        $this->assertSame(45, (int) $organisation->legal_notice_after);
        $this->assertSame('days', $organisation->legal_notice_unit);

        $this->put(route('settings.update'), $base + ['legal_notice_after' => '', 'legal_notice_unit' => 'months'])->assertSessionHasNoErrors();
        $this->assertNull($this->user->organisation->fresh()->legal_notice_after);

        $this->put(route('settings.update'), $base + ['legal_notice_after' => 0, 'legal_notice_unit' => 'months'])->assertSessionHasErrors('legal_notice_after');
        $this->put(route('settings.update'), $base + ['legal_notice_after' => 2, 'legal_notice_unit' => 'years'])->assertSessionHasErrors('legal_notice_unit');
    }

    public function test_the_delay_counts_from_the_oldest_unpaid_due_date(): void
    {
        $this->lease('2026-10-01');

        // Échéance du 01/10 : deux mois tombent le 01/12, un mois le 01/11.
        $this->delay(2);
        $this->assertCount(0, app(LegalNotice::class)->rows());

        $this->delay(1);
        $this->assertCount(1, app(LegalNotice::class)->rows());

        $this->delay(40, 'days');
        $this->assertCount(1, app(LegalNotice::class)->rows());
        $this->delay(41, 'days');
        $this->assertCount(0, app(LegalNotice::class)->rows());
    }

    public function test_a_tenant_who_pays_is_no_longer_concerned(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');
        app(RentService::class)->recordPayment($lease, 200000, '2026-11-09', 'Espèces', 'R-1');

        $this->assertCount(0, app(LegalNotice::class)->rows());
        $this->post(route('messages.legal', [$lease, 'whatsapp']))->assertRedirect()->assertSessionHas('error');
        $this->get(route('messages.legal.letter', $lease))->assertRedirect()->assertSessionHas('error');
    }

    public function test_the_message_lists_overdue_months_and_history_then_notes_the_opening(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');
        app(RentService::class)->recordPayment($lease, 25000, '2026-10-05', 'Espèces', 'R-1');
        Invoice::where('lease_id', $lease->id)->where('period', '2026-10')->first()->forceFill(['late_notice_opened_at' => '2026-10-20 10:00:00'])->save();

        $notice = app(LegalNotice::class)->forLease($lease);
        $message = InvoiceMessage::legalNotice($notice->lease, $notice);

        $this->assertStringContainsString('NOTIFICATION DE PROCÉDURE JUDICIAIRE', $message);
        $this->assertStringContainsString('Malgré nos précédents messages', $message);
        $this->assertStringContainsString('expulsion', $message);
        $this->assertStringContainsString('octobre 2026 : '.money(75000), $message);
        $this->assertStringContainsString('novembre 2026 : '.money(100000), $message);
        $this->assertStringContainsString('Total dû : '.money(175000), $message);
        $this->assertStringContainsString('20/10/2026', $message);

        $response = $this->post(route('messages.legal', [$lease, 'whatsapp']));
        $response->assertRedirect();
        $this->assertStringStartsWith('https://wa.me/237690000001?text=', $response->headers->get('Location'));
        $this->assertNotNull($lease->fresh()->legal_notice_opened_at);
    }

    public function test_without_history_the_message_does_not_claim_any_earlier_reminder(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');

        $notice = app(LegalNotice::class)->forLease($lease);

        $this->assertStringNotContainsString('Malgré', InvoiceMessage::legalNotice($notice->lease, $notice));
        $this->get(route('messages.index', ['etat' => 'procedure']))->assertSee('Aucun message précédent');
    }

    public function test_an_ended_lease_does_not_threaten_eviction(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-09-01');
        $lease->update(['end_date' => '2026-10-31']);

        $notice = app(LegalNotice::class)->forLease($lease->fresh());

        $this->assertStringNotContainsString('expulsion', InvoiceMessage::legalNotice($notice->lease, $notice));
    }

    public function test_a_notified_row_stops_waiting_until_a_new_debt_comes_back(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');
        $this->assertTrue(app(LegalNotice::class)->forLease($lease)->waiting);

        $this->post(route('messages.legal', [$lease, 'whatsapp']));
        $this->assertFalse(app(LegalNotice::class)->forLease($lease->fresh())->waiting);

        // La dette ancienne est réglée, une nouvelle dépasse le délai plus tard.
        Carbon::setTestNow('2027-02-10 09:00:00');
        Invoice::where('lease_id', $lease->id)->where('due_date', '<', '2027-01-01')->get()
            ->each(fn (Invoice $invoice) => $invoice->forceFill(['paid_amount' => $invoice->amount])->save());
        Invoice::create(['organisation_id' => $lease->organisation_id, 'lease_id' => $lease->id, 'period' => '2027-01', 'due_date' => '2027-01-01', 'amount' => 100000, 'paid_amount' => 0]);
        $this->assertTrue(app(LegalNotice::class)->forLease($lease->fresh())->waiting);
    }

    public function test_without_a_valid_number_only_the_letter_is_available(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01', null);

        $this->post(route('messages.legal', [$lease, 'whatsapp']))->assertRedirect()->assertSessionHas('error');
        $this->assertNull($lease->fresh()->legal_notice_opened_at);

        $letter = $this->get(route('messages.legal.letter', $lease));
        $letter->assertOk();
        $this->assertSame('application/pdf', $letter->headers->get('Content-Type'));
    }

    public function test_the_sms_fits_in_one_message_and_opens_the_phone_application(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');

        $notice = app(LegalNotice::class)->forLease($lease);
        $sms = InvoiceMessage::legalNoticeSms($notice->lease, $notice);

        $this->assertStringContainsString('Procedure judiciaire engagee', $sms);
        $this->assertStringContainsString('01/10/2026', $sms);
        $this->assertLessThanOrEqual(160, strlen($sms));
        $this->assertSame($sms, Str::ascii($sms));

        $response = $this->post(route('messages.legal', [$lease, 'sms']));
        $this->assertStringStartsWith('sms:+237690000001?&body=', $response->headers->get('Location'));
        $this->assertNotNull($lease->fresh()->legal_notice_opened_at);

        $this->post(route('messages.legal', [$lease, 'fax']))->assertNotFound();
    }

    public function test_the_sms_button_opens_the_messaging_application_on_every_device(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');

        $this->get(route('messages.index', ['etat' => 'procedure']))
            ->assertOk()
            ->assertSee(route('messages.legal', [$lease, 'sms']), false)
            ->assertDontSee('touch-only', false)
            ->assertDontSee('Copier le SMS');
    }

    public function test_the_letter_follows_the_model(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');
        app(RentService::class)->recordPayment($lease, 25000, '2026-10-05', 'Espèces', 'R-1');
        Invoice::where('lease_id', $lease->id)->where('period', '2026-10')->first()->forceFill(['late_notice_opened_at' => '2026-10-20 10:00:00'])->save();

        $notice = app(LegalNotice::class)->forLease($lease);
        $html = view('leases.legal-notice-pdf', [
            'notice' => $notice,
            'lease' => $notice->lease,
            'lastPayment' => $lease->payments()->first(),
            'organisation' => $this->user->organisation,
            'logoSrc' => null,
        ])->render();

        foreach (['PROC-202611-'.$lease->id, 'Sommes dues', '40 j', money(175000), '05/10/2026', 'Démarches déjà effectuées', '5. Pour régulariser', 'Signature du locataire'] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
    }

    public function test_another_organisation_cannot_reach_a_notice(): void
    {
        $this->delay(1);
        $lease = $this->lease('2026-10-01');

        $this->actingAs($this->owner('Beta'));

        $this->post(route('messages.legal', [$lease, 'whatsapp']))->assertNotFound();
        $this->get(route('messages.legal.letter', $lease))->assertNotFound();
    }
}
