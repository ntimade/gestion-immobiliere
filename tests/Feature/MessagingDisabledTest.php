<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Stay;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Envoi des messages désactivé : les boutons restent affichés mais grisés, et l'ouverture de WhatsApp ou des SMS est refusée.
class MessagingDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $organisation = Organisation::create(['name' => 'Alpha', 'code' => Organisation::generateCode('Alpha'), 'currency' => 'XAF', 'phone_country' => 'CM']);
        $this->actingAs($organisation->users()->create(['name' => 'P', 'email' => 'alpha@t.test', 'password' => 'secret-pass-1']));
        config(['messaging.enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_send_buttons_stay_visible_but_greyed_and_sending_is_refused(): void
    {
        $property = Property::create(['name' => 'Palmiers', 'city' => 'Kribi', 'kind' => 'mixed']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => 'A1', 'type' => 'apartment', 'rent' => 100000]);
        $lease = app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Luc', 'phone' => '+237699000001']), ['start_date' => '2026-11-01', 'due_day' => 5, 'rent' => 100000, 'deposit_amount' => 0]);
        $invoice = $lease->invoices()->firstOrFail();

        // La fiche du bail et la page des messages gardent leurs boutons, grisés par la page.
        $this->get(route('leases.show', $lease))->assertOk()->assertSee('data-messages-off', false)->assertSee('data-message-send', false);
        $this->get(route('messages.index', ['etat' => 'factures']))->assertOk()
            ->assertSee('L\'envoi des messages WhatsApp et SMS est désactivé')->assertSee('data-messages-off', false);

        $this->from(route('messages.index'))->post(route('messages.open', [$invoice, 'whatsapp']))
            ->assertRedirect(route('messages.index'))->assertSessionHas('error', 'L\'envoi des messages WhatsApp et SMS est désactivé.');
        $this->post(route('messages.late', [$lease, 'sms']))->assertSessionHas('error');
        $this->assertNull($invoice->fresh()->whatsapp_opened_at);

        // Rien d'autre n'est retiré : la facture et son PDF restent disponibles.
        $this->get(route('invoices.pdf', $invoice))->assertOk();
    }

    public function test_the_stay_thanks_message_is_refused_too(): void
    {
        $property = Property::create(['name' => 'Studio', 'city' => 'Kribi', 'kind' => 'mixed']);
        $unit = Unit::create(['property_id' => $property->id, 'number' => 'M1', 'type' => 'studio', 'rent' => 150000, 'furnished' => true, 'daily_rate' => 25000]);
        $stay = Stay::create([
            'unit_id' => $unit->id, 'guest_id' => Guest::create(['name' => 'Paul', 'phone' => '+237699000002'])->id,
            'check_in_at' => '2026-11-12 14:00', 'days' => 1, 'check_out_at' => '2026-11-13 14:00',
            'daily_rate' => 25000, 'amount' => 25000, 'paid_amount' => 25000,
        ]);

        $this->get(route('stays.show', $stay))->assertOk()->assertSee('Envoyer sur WhatsApp')->assertSee('data-messages-off', false);
        $this->post(route('stays.message', [$stay, 'whatsapp']))->assertSessionHas('error');
        $this->assertNull($stay->fresh()->whatsapp_opened_at);
    }

    public function test_sending_works_again_once_enabled(): void
    {
        config(['messaging.enabled' => true]);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-messages-off', false);
    }
}
