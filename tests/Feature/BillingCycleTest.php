<?php

namespace Tests\Feature;

use App\Http\Controllers\InvoiceMessageController;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use App\Support\InvoiceMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingCycleTest extends TestCase
{
    use RefreshDatabase;

    private const RENT = 100000.0;

    private User $user;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $organisation = Organisation::create(['name' => 'Alpha', 'code' => 'ALP-001', 'currency' => 'XAF']);
        $this->user = $organisation->users()->create(['name' => 'P', 'email' => 'alpha@t.test', 'password' => 'secret-pass-1']);
        $this->actingAs($this->user);
        $property = Property::create(['name' => 'Immeuble']);
        $this->unit = Unit::create(['property_id' => $property->id, 'number' => '1', 'type' => 'apartment', 'rent' => self::RENT]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enter(int $cycle, int $depositMonths, int $advanceMonths): Lease
    {
        $entry = $depositMonths + $advanceMonths;

        $this->post(route('leases.store', $this->unit), [
            'tenant_mode' => 'new',
            'tenant' => ['name' => 'Jean', 'gender' => 'M', 'phone' => '+237690000001'],
            'start_date' => '2026-11-01',
            'first_period' => '2026-11',
            'due_day' => 1,
            'billing_months' => $cycle,
            'rent' => self::RENT,
            'deposit_enabled' => $depositMonths > 0 ? 1 : 0,
            'deposit_months' => $depositMonths,
            'deposit_paid_at' => '2026-11-01',
            'entry_months' => $entry > 0 ? $entry : null,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Lease::firstOrFail();
    }

    private function goTo(Carbon $date): void
    {
        Carbon::setTestNow($date->copy()->setTime(9, 0));
        app(RentService::class)->syncOrganisation($this->user->organisation->fresh());
    }

    private function month(int $offset): Carbon
    {
        return Carbon::create(2026, 11, 1)->addMonthsNoOverflow($offset);
    }

    private function periods(int $from, int $count): array
    {
        return array_map(fn (int $i) => $this->month($from + $i)->format('Y-m'), range(0, $count - 1));
    }

    private function unpaidPeriods(Lease $lease): array
    {
        return Invoice::where('lease_id', $lease->id)->unpaid()->orderBy('period')->pluck('period')->all();
    }

    private function assertNoHoleInMonths(Lease $lease): void
    {
        $periods = Invoice::where('lease_id', $lease->id)->orderBy('period')->pluck('period')->all();

        $this->assertSame(array_values(array_unique($periods)), $periods);
        $this->assertSame($this->periods(0, count($periods)), $periods);
    }

    // Le texte réellement ouvert dans WhatsApp, au numéro du locataire.
    private function whatsapp(string $url): string
    {
        $location = $this->post($url)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/237690000001?text=', $location);

        return rawurldecode(substr($location, strlen('https://wa.me/237690000001?text=')));
    }

    // Le texte réellement ouvert dans la messagerie, au numéro du locataire, sans accent.
    private function sms(string $url): string
    {
        $location = $this->post($url)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('sms:+237690000001?&body=', $location);
        $text = rawurldecode(substr($location, strlen('sms:+237690000001?&body=')));
        $this->assertSame(Str::ascii($text), $text);

        return $text;
    }

    private function assertMonthsListed(string $text, int $from, int $count): void
    {
        foreach (range(0, $count - 1) as $i) {
            $this->assertStringContainsString($this->month($from + $i)->locale('fr')->isoFormat('MMMM YYYY').' : '.money(self::RENT), $text);
        }
    }

    private function assertDepositMentioned(string $text, int $deposit): void
    {
        $this->assertStringContainsString($deposit > 0
            ? 'Caution : '.money($deposit * self::RENT).' ('.$deposit.' mois de loyer)'
            : 'Caution : aucune enregistrée', $text);
    }

    public static function scenarios(): array
    {
        $all = [];

        foreach ([1, 2, 3, 6, 12] as $cycle) {
            foreach ([0, 2, 3] as $deposit) {
                foreach ([0, 1, 4, 8] as $advance) {
                    $all["cycle {$cycle} mois, caution {$deposit}, avance {$advance}"] = [$cycle, $deposit, $advance];
                }
            }
        }

        return $all;
    }

    #[DataProvider('scenarios')]
    public function test_deposit_advance_and_cycle_work_together(int $cycle, int $deposit, int $advance): void
    {
        $lease = $this->enter($cycle, $deposit, $advance);

        // À l'entrée : la caution d'un côté, l'avance de l'autre.
        $this->assertSame($deposit * self::RENT, (float) $lease->deposit_amount);
        $this->assertSame($deposit * self::RENT, round((float) $lease->depositMovements()->sum('amount'), 3));
        $this->assertSame($advance * self::RENT, (float) Payment::sum('amount'));
        $this->assertSame($advance * self::RENT, (float) Invoice::where('lease_id', $lease->id)->sum('paid_amount'));

        if ($advance > 0) {
            $this->assertSame($this->month($advance)->subDay()->format('d/m/Y'), $lease->paidThrough()->format('d/m/Y'));
        } else {
            $this->assertNull($lease->paidThrough());
        }

        // Trois cycles de suite : le premier commence juste après l'avance, chacun demande exactement son nombre de mois.
        $paidMonths = $advance;

        for ($round = 1; $round <= 3; $round++) {
            $dueDate = $this->month($paidMonths);

            if ($paidMonths > 0) {
                $this->goTo($dueDate->copy()->subDays(5));
                $this->assertSame([], $this->unpaidPeriods($lease), 'Rien de dû avant la période de rappel');
                $this->assertSame(0, InvoiceMessageController::remindersToSend()->count());
            }

            $this->goTo($dueDate->copy()->subDays(2));

            if ($paidMonths === 0) {
                // Sans avance, le premier cycle existe dès l'entrée : l'échéance est le jour d'entrée.
                $this->goTo($dueDate);
            }

            $this->assertSame($this->periods($paidMonths, $cycle), $this->unpaidPeriods($lease));
            $this->assertSame($cycle * self::RENT, $lease->fresh()->unpaidTotal());
            $this->assertSame(
                [$dueDate->toDateString()],
                Invoice::where('lease_id', $lease->id)->unpaid()->get()->map(fn (Invoice $i) => $i->due_date->toDateString())->unique()->values()->all(),
            );

            // Une seule ligne de rappel, qui disparaît une fois le message ouvert.
            $reminders = InvoiceMessageController::remindersToSend()->get();
            $this->assertCount(1, $reminders);
            $this->assertSame($cycle * self::RENT, $reminders->first()->cycleBalance());
            $first = $reminders->first();
            $total = money($cycle * self::RENT);

            // Le rappel, sur WhatsApp et par SMS : tout le cycle, et une seule fois.
            $text = $this->whatsapp(route('messages.reminder', [$first, 'whatsapp']));
            $this->assertStringContainsString('RAPPEL DE LOYER', $text);
            $this->assertStringContainsString('Total à payer : '.$total, $text);
            $this->assertMonthsListed($text, $paidMonths, $cycle);
            $this->assertDepositMentioned($text, $deposit);
            $this->assertSame(0, InvoiceMessageController::remindersToSend()->count());

            $sms = $this->sms(route('messages.reminder', [$first, 'sms']));
            $this->assertStringContainsString(Str::ascii($total), $sms);
            $this->assertStringContainsString($dueDate->format('d/m/Y'), $sms);

            // La facture, sur WhatsApp et par SMS.
            $text = $this->whatsapp(route('messages.open', [$first, 'whatsapp']));
            $this->assertStringContainsString('FACTURE DE LOYER', $text);
            $this->assertStringContainsString('Total à payer : '.$total, $text);
            $this->assertMonthsListed($text, $paidMonths, $cycle);
            $this->assertDepositMentioned($text, $deposit);
            $cycle > 1
                ? $this->assertStringContainsString('Facturation : '.mb_strtolower($lease->billingLabel()), $text)
                : $this->assertStringNotContainsString('Facturation', $text);

            $sms = $this->sms(route('messages.open', [$first, 'sms']));
            $this->assertStringContainsString(Str::ascii($total), $sms);
            $this->assertStringContainsString(Str::ascii($this->month($paidMonths)->locale('fr')->isoFormat('MMMM YYYY')), $sms);

            // Le client règle son cycle, et la confirmation porte la nouvelle date.
            $payment = app(RentService::class)->recordPayment($lease->fresh(), $cycle * self::RENT, $dueDate->toDateString(), 'Espèces', 'R-'.$round);
            $paidMonths += $cycle;
            $until = $this->month($paidMonths)->subDay()->format('d/m/Y');

            $this->assertSame(0.0, $lease->fresh()->unpaidTotal());
            $this->assertSame($until, $lease->fresh()->paidThrough()->format('d/m/Y'));

            $text = $this->whatsapp(route('messages.payment', [$payment, 'whatsapp']));
            $this->assertStringContainsString('CONFIRMATION DE PAIEMENT', $text);
            $this->assertStringContainsString($total, $text);
            $this->assertStringContainsString('Loyer payé jusqu\'au *'.$until.'*', $text);
            $this->assertStringContainsString('Votre loyer est à jour', $text);
            $this->assertDepositMentioned($text, $deposit);

            $sms = $this->sms(route('messages.payment', [$payment, 'sms']));
            $this->assertStringContainsString('Paye jusqu\'au '.$until, $sms);
            $this->assertStringContainsString('Loyer a jour', $sms);

            // Un cycle réglé ne se réclame plus.
            $this->post(route('messages.open', [$first, 'whatsapp']))->assertSessionHas('error');
            $this->post(route('messages.reminder', [$first, 'sms']))->assertSessionHas('error');
        }

        $this->assertNoHoleInMonths($lease);

        // La caution n'a pas bougé pendant tous ces versements.
        $fresh = $lease->fresh();
        $this->assertSame($deposit * self::RENT, $fresh->depositHeld());
        $this->assertSame($deposit > 0 ? 1 : 0, $fresh->depositMovements()->count());
    }

    #[DataProvider('scenarios')]
    public function test_a_new_advance_in_the_middle_keeps_the_cycle(int $cycle, int $deposit, int $advance): void
    {
        $lease = $this->enter($cycle, $deposit, $advance);
        $dueDate = $this->month($advance);
        $this->goTo($dueDate);

        // Le client règle son cycle et avance 2 mois de plus : le cycle suivant part après ces mois.
        app(RentService::class)->recordPayment($lease->fresh(), ($cycle + 2) * self::RENT, $dueDate->toDateString(), 'Espèces', 'R');
        $covered = $advance + $cycle + 2;

        $this->assertSame($this->month($covered)->subDay()->format('d/m/Y'), $lease->fresh()->paidThrough()->format('d/m/Y'));
        $this->assertSame(0.0, $lease->fresh()->unpaidTotal());

        $this->goTo($this->month($covered)->subDays(1));
        $this->assertSame($this->periods($covered, $cycle), $this->unpaidPeriods($lease));
        $this->assertSame($cycle * self::RENT, $lease->fresh()->unpaidTotal());
        $this->assertNoHoleInMonths($lease);
    }

    #[DataProvider('scenarios')]
    public function test_late_and_legal_notifications_follow_the_cycle(int $cycle, int $deposit, int $advance): void
    {
        $this->user->organisation->update(['legal_notice_after' => 1, 'legal_notice_unit' => 'months']);
        $lease = $this->enter($cycle, $deposit, $advance);

        // L'échéance du cycle reste impayée pendant plus d'un mois.
        $dueDate = $this->month($advance);
        $this->goTo($dueDate->copy()->addDays(35));

        $overdue = Invoice::where('lease_id', $lease->id)->overdue()->orderBy('period')->get();
        $total = money($overdue->sum(fn (Invoice $invoice) => $invoice->balance()));
        $this->assertGreaterThanOrEqual($cycle, $overdue->count());
        $this->assertSame($dueDate->toDateString(), $overdue->first()->due_date->toDateString());

        // La relance, sur WhatsApp et par SMS.
        $text = $this->whatsapp(route('messages.late', [$lease, 'whatsapp']));
        $this->assertStringContainsString('LOYER EN RETARD', $text);
        $this->assertStringContainsString('Total à payer : '.$total, $text);
        $this->assertStringContainsString($overdue->last()->label(), $text);
        $this->assertDepositMentioned($text, $deposit);
        $this->assertStringContainsString(Str::ascii($total), $this->sms(route('messages.late', [$lease, 'sms'])));

        // La notification de procédure, sur WhatsApp et par SMS, avec sa lettre.
        $text = $this->whatsapp(route('messages.legal', [$lease, 'whatsapp']));
        $this->assertStringContainsString('NOTIFICATION DE PROCÉDURE JUDICIAIRE', $text);
        $this->assertStringContainsString('Total dû : '.$total, $text);
        $this->assertStringContainsString($dueDate->format('d/m/Y'), $text);
        $this->assertDepositMentioned($text, $deposit);

        $sms = $this->sms(route('messages.legal', [$lease, 'sms']));
        $this->assertStringContainsString('Procedure judiciaire engagee', $sms);
        $this->assertStringContainsString(Str::ascii($total), $sms);

        $this->get(route('messages.legal.letter', $lease))->assertOk();
        $this->get(route('messages.index', ['etat' => 'procedure']))->assertOk()->assertSee($total);
        $this->get(route('tenants.history', $lease->tenant_id))->assertOk();

        // La caution n'est jamais déduite ni touchée par ces messages.
        $this->assertSame($deposit * self::RENT, $lease->fresh()->depositHeld());
    }

    public function test_the_payment_sms_stays_short_for_a_long_advance(): void
    {
        $lease = $this->enter(3, 2, 0);
        $payment = app(RentService::class)->recordPayment($lease, 1200000, '2026-11-01', 'Espèces', 'R');

        $sms = $this->sms(route('messages.payment', [$payment, 'sms']));

        $this->assertStringContainsString('Recu '.Str::ascii(money(1200000)), $sms);
        $this->assertStringContainsString('Paye jusqu\'au 31/10/2027', $sms);
        $this->assertLessThanOrEqual(160, strlen($sms));
    }

    public function test_the_example_ten_months_with_two_of_deposit_billed_quarterly(): void
    {
        $lease = $this->enter(3, 2, 8);

        $this->assertSame(200000.0, $lease->depositHeld());
        $this->assertSame('30/06/2027', $lease->paidThrough()->format('d/m/Y'));
        $this->assertSame('01/07/2027', $lease->nextDueDate()->format('d/m/Y'));

        $this->goTo(Carbon::create(2027, 6, 29));
        $this->assertSame(['2027-07', '2027-08', '2027-09'], $this->unpaidPeriods($lease));
        $this->assertSame(300000.0, $lease->fresh()->unpaidTotal());
    }

    public function test_a_small_advance_does_not_make_the_rest_of_a_cycle_overdue(): void
    {
        $lease = $this->enter(3, 2, 2);

        $this->assertSame([], $this->unpaidPeriods($lease));
        $this->assertSame('31/12/2026', $lease->paidThrough()->format('d/m/Y'));
        $this->assertSame(0, Invoice::where('lease_id', $lease->id)->overdue()->count());
    }

    public function test_a_partly_paid_cycle_leaves_the_rest_due_on_the_same_date(): void
    {
        $lease = $this->enter(3, 0, 0);
        app(RentService::class)->recordPayment($lease, 150000, '2026-11-01', 'Espèces', 'R');

        $this->assertSame(['2026-12', '2027-01'], $this->unpaidPeriods($lease));
        $this->assertSame(150000.0, $lease->fresh()->unpaidTotal());
        $this->assertSame('30/11/2026', $lease->fresh()->paidThrough()->format('d/m/Y'));
        $this->assertSame('01/11/2026', $lease->fresh()->nextDueDate()->format('d/m/Y'));

        $this->goTo(Carbon::create(2026, 11, 2));
        $this->assertSame(2, Invoice::where('lease_id', $lease->id)->overdue()->count());
    }

    public function test_the_messages_show_the_whole_cycle(): void
    {
        $lease = $this->enter(3, 2, 0);
        $invoice = Invoice::with(['lease.tenant', 'lease.unit.property'])->where('lease_id', $lease->id)->orderBy('period')->first();

        $message = InvoiceMessage::whatsapp($invoice);
        $this->assertStringContainsString('NOVEMBRE 2026 À JANVIER 2027', $message);
        $this->assertStringContainsString('Facturation : trimestrielle', $message);
        $this->assertStringContainsString('novembre 2026 : '.money(100000), $message);
        $this->assertStringContainsString('janvier 2027 : '.money(100000), $message);
        $this->assertStringContainsString('Total à payer : '.money(300000), $message);
        $this->assertStringContainsString('2 mois de loyer', $message);

        $sms = InvoiceMessage::sms($invoice);
        $this->assertStringContainsString('novembre 2026 a janvier 2027', $sms);
        $this->assertStringContainsString(money(300000), $sms);
    }

    public function test_the_message_lists_show_one_line_per_cycle_and_mark_all_its_months(): void
    {
        $lease = $this->enter(3, 2, 0);

        $this->get(route('messages.index', ['etat' => 'factures']))->assertOk()
            ->assertSee('novembre 2026 à janvier 2027')->assertSee(money(300000));

        $first = Invoice::where('lease_id', $lease->id)->orderBy('period')->first();
        $this->post(route('messages.open', [$first, 'whatsapp']))->assertRedirect();

        $this->assertSame(3, Invoice::where('lease_id', $lease->id)->whereNotNull('whatsapp_opened_at')->count());
    }

    public function test_a_monthly_lease_behaves_as_before(): void
    {
        $lease = $this->enter(1, 2, 0);
        $invoice = Invoice::with(['lease.tenant', 'lease.unit.property'])->where('lease_id', $lease->id)->first();

        $this->assertSame(['2026-11'], $this->unpaidPeriods($lease));
        $this->assertStringContainsString('NOVEMBRE 2026', InvoiceMessage::whatsapp($invoice));
        $this->assertStringNotContainsString('À ', explode("\n", InvoiceMessage::whatsapp($invoice))[0]);
        $this->assertStringNotContainsString('Facturation', InvoiceMessage::whatsapp($invoice));
    }

    public function test_without_cycle_field_a_lease_stays_monthly(): void
    {
        $lease = app(RentService::class)->startLease($this->unit, \App\Models\Tenant::create(['name' => 'Ana']), [
            'start_date' => '2026-11-01', 'due_day' => 1, 'rent' => self::RENT,
        ]);

        $this->assertSame(1, (int) $lease->fresh()->billing_months);
        $this->assertSame(['2026-11'], $this->unpaidPeriods($lease));
    }

    public function test_the_cycle_can_change_for_the_next_invoices_only(): void
    {
        $lease = $this->enter(1, 0, 0);
        app(RentService::class)->recordPayment($lease, self::RENT, '2026-11-01', 'Espèces', 'R');

        $this->put(route('leases.update', $lease), ['due_day' => 1, 'billing_months' => 3, 'rent' => self::RENT, 'deposit_amount' => 0])->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::where('lease_id', $lease->id)->count());

        $this->goTo(Carbon::create(2026, 11, 29));
        $this->assertSame(['2026-12', '2027-01', '2027-02'], $this->unpaidPeriods($lease));
    }

    public function test_the_cycle_stops_at_the_end_of_the_lease(): void
    {
        $lease = $this->enter(1, 0, 0);
        app(RentService::class)->recordPayment($lease, self::RENT, '2026-11-01', 'Espèces', 'R');
        $lease->update(['billing_months' => 3, 'end_date' => '2027-01-15']);

        $this->goTo(Carbon::create(2026, 11, 29));

        $this->assertSame(['2026-12', '2027-01'], $this->unpaidPeriods($lease));
    }

    public function test_the_cycle_is_validated_and_shown(): void
    {
        $base = ['tenant_mode' => 'new', 'tenant' => ['name' => 'Jean'], 'start_date' => '2026-11-01', 'due_day' => 1, 'rent' => self::RENT];

        $this->post(route('leases.store', $this->unit), $base + ['billing_months' => 0])->assertSessionHasErrors('billing_months');
        $this->post(route('leases.store', $this->unit), $base + ['billing_months' => 25])->assertSessionHasErrors('billing_months');

        $lease = $this->enter(3, 2, 0);
        $this->get(route('leases.show', $lease))->assertOk()->assertSee('Trimestrielle');
        $this->get(route('leases.statement', $lease))->assertOk()->assertSee('trimestrielle');
        $this->get(route('leases.create', Unit::create(['property_id' => $this->unit->property_id, 'number' => '2', 'type' => 'studio', 'rent' => 50000])))
            ->assertOk()->assertSee('name="billing_months"', false);
    }

    public function test_two_months_and_custom_cycles_have_a_clear_label(): void
    {
        $this->assertSame('Tous les 2 mois', (new Lease(['billing_months' => 2]))->billingLabel());
        $this->assertSame('Tous les 5 mois', (new Lease(['billing_months' => 5]))->billingLabel());
        $this->assertSame('Mensuelle', (new Lease(['billing_months' => 1]))->billingLabel());
        $this->assertSame('Annuelle', (new Lease(['billing_months' => 12]))->billingLabel());
    }
}
