<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceMessageController;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\RentService;
use App\Support\InvoiceMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');

        $organisation = Organisation::create(['name' => 'Résidence Alpha', 'code' => 'RAX-001', 'currency' => 'XAF', 'phone_country' => 'CM', 'phone' => '+237677000000']);
        $this->actingAs($organisation->users()->create(['name' => 'Proprio', 'email' => 'p@t.test', 'password' => 'secret-pass-1']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lease(int $dueDay, string $name = 'Jean Locataire', ?string $phone = '+237699000000', string $start = '2026-11-01'): Lease
    {
        $property = Property::firstOrCreate(['name' => 'Immeuble A']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => (string) (Unit::count() + 1), 'type' => 'apartment', 'rent' => 50000]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => $name, 'phone' => $phone, 'gender' => 'M']), [
            'start_date' => $start, 'due_day' => $dueDay, 'rent' => 50000,
        ]);
    }

    private function reminders(): array
    {
        return InvoiceMessageController::remindersToSend()->with('lease.tenant')->get()->pluck('lease.tenant.name')->all();
    }

    public function test_tenant_appears_in_the_list_three_days_before_the_due_date(): void
    {
        $this->lease(14, 'Trop tôt');
        $this->lease(13, 'Dans trois jours');
        $this->lease(12, 'Dans deux jours');
        $this->lease(10, 'Aujourd\'hui');
        $this->lease(9, 'Déjà en retard');

        $this->assertEqualsCanonicalizing(['Dans trois jours', 'Dans deux jours', 'Aujourd\'hui'], $this->reminders());

        $this->get(route('messages.index'))->assertOk()
            ->assertSee('Dans trois jours')->assertDontSee('Trop tôt')->assertDontSee('Déjà en retard');
    }

    public function test_each_tenant_appears_at_his_own_date(): void
    {
        $this->lease(13, 'Jean');
        $this->lease(20, 'Awa');

        $this->assertSame(['Jean'], $this->reminders());

        Carbon::setTestNow('2026-11-17 09:00:00');

        $this->assertSame(['Awa'], $this->reminders());
    }

    public function test_reminder_for_the_next_month_appears_at_the_end_of_the_month(): void
    {
        $this->lease(1, 'Jean');

        Carbon::setTestNow('2026-11-28 09:00:00');
        $this->get(route('dashboard'));
        $this->assertSame(['Jean'], $this->reminders());
    }

    public function test_opening_the_reminder_prepares_the_message_and_removes_the_line(): void
    {
        $lease = $this->lease(13);
        $invoice = $lease->invoices()->sole();

        $response = $this->post(route('messages.reminder', [$invoice, 'whatsapp']));

        $response->assertRedirectContains('https://wa.me/237699000000?text=');
        $text = rawurldecode(substr($response->headers->get('Location'), strlen('https://wa.me/237699000000?text=')));
        $this->assertStringContainsString('*RAPPEL DE LOYER*', $text);
        $this->assertSame(3, substr_count($text, '━━━━━━━━━━━━━━━━━━'));
        $this->assertStringContainsString('_Si vous avez déjà payé, ne tenez pas compte de ce message._', $text);
        $this->assertStringContainsString('Monsieur *Jean Locataire*', $text);
        $this->assertStringContainsString('dans 3 jours', $text);
        $this->assertStringContainsString('13/11/2026', $text);
        $this->assertStringContainsString('Immeuble A, n° 1', $text);
        $this->assertStringContainsString('50 000 XAF', $text);
        $this->assertStringContainsString('Résidence Alpha', $text);

        $this->assertNotNull($invoice->fresh()->reminder_opened_at);
        $this->assertSame([], $this->reminders());
    }

    public function test_sms_reminder_is_short_without_accents(): void
    {
        $lease = $this->lease(13);
        $invoice = $lease->invoices()->sole();

        $response = $this->post(route('messages.reminder', [$invoice, 'sms']));

        $response->assertRedirectContains('sms:+237699000000?&body=');
        $text = InvoiceMessage::reminderSms($invoice->fresh());
        $this->assertSame($text, \Illuminate\Support\Str::ascii($text));
        $this->assertLessThanOrEqual(160, strlen($text));
    }

    public function test_month_paid_in_advance_gets_no_reminder(): void
    {
        $lease = $this->lease(13);
        app(RentService::class)->recordPayment($lease, 100000, '2026-11-10', null, null);
        $this->assertSame([], $this->reminders());

        Carbon::setTestNow('2026-12-11 09:00:00');
        $this->get(route('dashboard'));

        $this->assertSame([], $this->reminders());
        $this->assertSame('2026-12', $lease->invoices()->orderByDesc('period')->first()->period);

        Carbon::setTestNow('2027-01-10 09:00:00');
        $this->get(route('dashboard'));
        $this->assertSame(['Jean Locataire'], $this->reminders());
    }

    public function test_partly_paid_rent_is_still_reminded_for_what_remains(): void
    {
        $lease = $this->lease(13);
        $invoice = $lease->invoices()->sole();
        app(RentService::class)->recordPayment($lease, 20000, '2026-11-10', null, null);

        $this->assertSame(['Jean Locataire'], $this->reminders());
        $this->assertStringContainsString('30 000 XAF', InvoiceMessage::reminder($invoice->fresh()));
    }

    public function test_paid_rent_refuses_a_reminder(): void
    {
        $lease = $this->lease(13);
        $invoice = $lease->invoices()->sole();
        app(RentService::class)->recordPayment($lease, 50000, '2026-11-10', null, null);

        $this->post(route('messages.reminder', [$invoice, 'whatsapp']))->assertSessionHas('error');
    }

    public function test_paid_rent_refuses_the_invoice_message_on_both_channels(): void
    {
        $lease = $this->lease(13);
        $invoice = $lease->invoices()->sole();
        app(RentService::class)->recordPayment($lease, 50000, '2026-11-10', null, null);

        foreach (['whatsapp', 'sms'] as $channel) {
            $this->from(route('leases.show', $lease))->post(route('messages.open', [$invoice, $channel]))
                ->assertRedirect(route('leases.show', $lease))->assertSessionHas('error', 'Ce loyer est déjà réglé.');
        }

        $this->assertNull($invoice->fresh()->whatsapp_opened_at);
        $this->assertNull($invoice->fresh()->sms_opened_at);
    }

    public function test_month_paid_in_advance_refuses_every_message(): void
    {
        $lease = $this->lease(13);
        app(RentService::class)->recordPayment($lease, 150000, '2026-11-10', null, null);
        $future = $lease->invoices()->where('period', '2026-12')->sole();

        $this->post(route('messages.open', [$future, 'whatsapp']))->assertSessionHas('error');
        $this->post(route('messages.reminder', [$future, 'sms']))->assertSessionHas('error');
        $this->assertNull($future->fresh()->reminder_opened_at);
    }

    public function test_buttons_follow_the_status_of_each_month(): void
    {
        $lease = $this->lease(5, 'Jean Locataire', '+237699000000', '2026-10-05');
        $october = $lease->invoices()->where('period', '2026-10')->sole();
        $november = $lease->invoices()->where('period', '2026-11')->sole();
        app(RentService::class)->recordPayment($lease, 50000, '2026-11-10', null, null);

        $page = $this->get(route('leases.show', $lease))->assertOk();

        // Octobre est réglé : plus de formulaire d'envoi. Novembre reste à payer : les boutons restent actifs.
        $page->assertDontSee(route('messages.open', [$october, 'whatsapp']), false)->assertDontSee(route('messages.open', [$october, 'sms']), false);
        $page->assertSee(route('messages.open', [$november, 'whatsapp']), false)->assertSee(route('messages.open', [$november, 'sms']), false);
        $page->assertSee('Loyer déjà réglé : envoi désactivé');
        $page->assertSee(route('invoices.pdf', $october), false);
    }

    public function test_partly_paid_rent_can_still_be_sent(): void
    {
        $lease = $this->lease(13);
        $invoice = $lease->invoices()->sole();
        app(RentService::class)->recordPayment($lease, 20000, '2026-11-10', null, null);

        $this->post(route('messages.open', [$invoice, 'whatsapp']))->assertRedirectContains('https://wa.me/');
        $this->assertNotNull($invoice->fresh()->whatsapp_opened_at);
    }

    public function test_cancelling_the_payment_reactivates_the_messages(): void
    {
        $lease = $this->lease(13);
        $invoice = $lease->invoices()->sole();
        $payment = app(RentService::class)->recordPayment($lease, 50000, '2026-11-10', null, null);
        $this->post(route('messages.open', [$invoice, 'whatsapp']))->assertSessionHas('error');

        app(RentService::class)->cancelPayment($payment);

        $this->post(route('messages.open', [$invoice, 'whatsapp']))->assertRedirectContains('https://wa.me/');
    }

    public function test_late_notice_is_refused_once_the_arrears_are_paid(): void
    {
        $lease = $this->lease(5, 'Jean Locataire', '+237699000000', '2026-10-05');
        app(RentService::class)->recordPayment($lease, 100000, '2026-11-10', null, null);

        $this->post(route('messages.late', [$lease, 'whatsapp']))->assertSessionHas('error');
    }

    public function test_payment_confirmation_stays_available_once_paid(): void
    {
        $lease = $this->lease(13);
        $payment = app(RentService::class)->recordPayment($lease, 50000, '2026-11-10', null, null);

        $this->post(route('messages.payment', [$payment, 'whatsapp']))->assertRedirectContains('https://wa.me/');
    }

    public function test_invoice_message_details_every_unpaid_month_with_what_remains(): void
    {
        $lease = $this->lease(18, 'Marie', '+237699000000', '2026-09-18');
        app(RentService::class)->recordPayment($lease, 75000, '2026-11-01', null, null);
        $relations = ['lease.tenant', 'lease.unit.property'];
        $november = $lease->invoices()->with($relations)->where('period', '2026-11')->sole();

        $text = InvoiceMessage::whatsapp($november);

        $this->assertStringNotContainsString('septembre 2026', $text);
        $this->assertStringContainsString('*Reste à payer par mois :*', $text);
        $this->assertStringContainsString('• octobre 2026 : 25 000 XAF (en retard)', $text);
        $this->assertStringContainsString('• novembre 2026 : 50 000 XAF', $text);
        $this->assertStringNotContainsString('novembre 2026 : 50 000 XAF (en retard)', $text);
        $this->assertStringContainsString('*Total à payer : 75 000 XAF*', $text);
        $this->assertStringContainsString('Dont en retard : 25 000 XAF', $text);
        $this->assertStringContainsString('À régler avant le *18/11/2026*', $text);
        $this->assertStringNotContainsString('Impayés des mois précédents', $text);
    }

    public function test_message_from_an_old_month_also_includes_the_later_unpaid_months(): void
    {
        $lease = $this->lease(5, 'Eric', '+237699000000', '2026-10-05');
        $relations = ['lease.tenant', 'lease.unit.property'];
        $october = $lease->invoices()->with($relations)->where('period', '2026-10')->sole();

        $text = InvoiceMessage::whatsapp($october);

        $this->assertStringContainsString('• octobre 2026 : 50 000 XAF (en retard)', $text);
        $this->assertStringContainsString('• novembre 2026 : 50 000 XAF (en retard)', $text);
        $this->assertStringContainsString('*Total à payer : 100 000 XAF*', $text);
        $this->assertStringNotContainsString('Dont en retard', $text);
        $this->assertStringContainsString('100 000 XAF', InvoiceMessage::sms($october));
    }

    public function test_reminder_details_every_unpaid_month(): void
    {
        $lease = $this->lease(13, 'Awa', '+237699000000', '2026-10-13');
        $relations = ['lease.tenant', 'lease.unit.property'];
        $november = $lease->invoices()->with($relations)->where('period', '2026-11')->sole();

        $text = InvoiceMessage::reminder($november);

        $this->assertStringContainsString('• octobre 2026 : 50 000 XAF (en retard)', $text);
        $this->assertStringContainsString('• novembre 2026 : 50 000 XAF', $text);
        $this->assertStringContainsString('*Total à payer : 100 000 XAF*', $text);
        $this->assertStringContainsString('100 000 XAF', InvoiceMessage::reminderSms($november));
    }

    public function test_printed_invoice_shows_the_same_total_as_the_message(): void
    {
        $lease = $this->lease(5, 'Eric', '+237699000000', '2026-10-05');
        $october = $lease->invoices()->where('period', '2026-10')->sole();

        $this->get(route('invoices.show', $october))->assertOk()
            ->assertSee('Reste dû sur novembre 2026')->assertSee('100 000 XAF');
    }

    // Les émojis arrivent cassés (�) dans WhatsApp : aucun message n'en contient.
    public function test_messages_contain_no_emoji(): void
    {
        $late = $this->lease(5, 'Paul Retard', '+237699000000', '2026-10-05');
        $partial = $this->lease(13, 'Awa Partiel', '+237699000001');
        $payment = app(RentService::class)->recordPayment($partial, 20000, '2026-11-10', 'Espèces', 'R-1');
        $paid = $this->lease(20, 'Jean Solde', '+237699000002');
        $full = app(RentService::class)->recordPayment($paid, 50000, '2026-11-10', null, null);
        $relations = ['lease.tenant', 'lease.unit.property'];
        $invoice = $partial->invoices()->with($relations)->sole();
        $overdue = $late->invoices()->with($relations)->overdue()->get();

        foreach ([
            InvoiceMessage::whatsapp($invoice),
            InvoiceMessage::whatsapp($overdue->first()),
            InvoiceMessage::reminder($invoice),
            InvoiceMessage::payment($payment->fresh(['lease.tenant', 'lease.unit.property', 'allocations.invoice'])),
            InvoiceMessage::payment($full->fresh(['lease.tenant', 'lease.unit.property', 'allocations.invoice'])),
            InvoiceMessage::lateNotice($late->load(['tenant', 'unit.property']), $overdue),
        ] as $message) {
            $this->assertDoesNotMatchRegularExpression('/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{FFFD}]/u', $message);
        }

        $this->assertStringContainsString('*Total à payer : 30 000 XAF*', InvoiceMessage::reminder($invoice));
        $this->assertStringContainsString('Tél. : +237677000000', InvoiceMessage::reminder($invoice));
    }

    public function test_tenant_without_a_number_is_flagged_not_lost(): void
    {
        $lease = $this->lease(13, 'Sans Numéro', null);

        $this->assertSame([], $this->reminders());

        $this->get(route('messages.index'))->assertOk()->assertSee('Sans Numéro')->assertSee('Corriger le numéro');
        $this->post(route('messages.reminder', [$lease->invoices()->sole(), 'whatsapp']))->assertSessionHas('error');
    }

    public function test_payment_confirmation_lists_the_months_covered(): void
    {
        $lease = $this->lease(13);
        $payment = app(RentService::class)->recordPayment($lease, 150000, '2026-11-10', 'Mobile Money', 'MM-77');

        $text = InvoiceMessage::payment($payment->fresh(['lease.tenant', 'lease.unit.property', 'allocations.invoice']));

        $this->assertStringContainsString('150 000 XAF', $text);
        $this->assertStringContainsString('novembre 2026, décembre 2026, janvier 2027', $text);
        $this->assertStringContainsString('Votre loyer est à jour', $text);
        $this->assertStringContainsString('MM-77', $text);

        $this->post(route('messages.payment', [$payment, 'whatsapp']))->assertRedirectContains('https://wa.me/');
        $this->assertNotNull(Payment::first()->whatsapp_opened_at);
    }

    public function test_late_notice_lists_all_overdue_months_and_leaves_the_list_until_a_new_month_is_late(): void
    {
        $lease = $this->lease(5, 'Jean Locataire', '+237699000000', '2026-10-05');
        $this->assertSame(2, $lease->invoices()->overdue()->count());
        $this->assertSame(1, InvoiceMessageController::waitingCount());

        $response = $this->post(route('messages.late', [$lease, 'whatsapp']));
        $response->assertRedirectContains('https://wa.me/');
        $text = rawurldecode($response->headers->get('Location'));
        $this->assertStringContainsString('octobre 2026', $text);
        $this->assertStringContainsString('novembre 2026', $text);
        $this->assertStringContainsString('100 000 XAF', $text);

        $this->assertSame(0, InvoiceMessageController::waitingCount());

        Carbon::setTestNow('2026-12-06 09:00:00');
        $this->get(route('dashboard'));
        $this->assertSame(1, InvoiceMessageController::waitingCount());
    }

    public function test_invoice_message_and_pdf(): void
    {
        $lease = $this->lease(20);
        $invoice = $lease->invoices()->sole();

        $response = $this->post(route('messages.open', [$invoice, 'whatsapp']));
        $response->assertRedirectContains('https://wa.me/');
        $text = rawurldecode($response->headers->get('Location'));
        $this->assertStringContainsString('*FACTURE DE LOYER — NOVEMBRE 2026*', $text);
        $this->assertStringContainsString($invoice->number(), $text);
        $this->assertNotNull($invoice->fresh()->whatsapp_opened_at);

        $this->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_installed_app_shows_a_button_instead_of_redirecting(): void
    {
        $invoice = $this->lease(13)->invoices()->sole();

        $this->post(route('messages.reminder', [$invoice, 'whatsapp']), ['via' => 'app'])->assertOk()->assertSee('Ouvrir WhatsApp');
    }

    public function test_menu_counts_what_waits_today(): void
    {
        $this->lease(13);

        $this->get(route('dashboard'))->assertOk()->assertSee('nav-count', false)->assertSee('1 rappel(s) de loyer à envoyer');
    }
}
