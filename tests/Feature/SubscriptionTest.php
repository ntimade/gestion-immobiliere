<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\SupportAction;
use App\Models\SupportAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private SupportAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->admin = SupportAdmin::create(['name' => 'Agent', 'email' => 'support@tower.test', 'password' => 'support-pass-1']);
    }

    private function account(string $email = 'a@test.test', string $name = 'Eau Alpha'): User
    {
        $organisation = Organisation::create(['name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF']);

        return $organisation->users()->create(['name' => 'Propriétaire', 'email' => $email, 'password' => 'secret-pass-1']);
    }

    private function plan(int $months = 1, float $price = 5000): SubscriptionPlan
    {
        $plan = SubscriptionPlan::where('months', $months)->firstOrFail();
        $plan->update(['price' => $price, 'currency' => 'XAF', 'is_active' => true]);

        return $plan;
    }

    private function asSupport(): void
    {
        $this->actingAs($this->admin, 'support');
        Auth::shouldUse('web');
    }

    public function test_four_durations_exist_and_are_offered_only_when_active_with_a_price(): void
    {
        $this->assertSame([1, 3, 6, 12], SubscriptionPlan::orderBy('months')->pluck('months')->all());

        $this->actingAs($this->account());
        $this->get(route('subscription.index'))->assertOk()->assertSee(__('Mensuel'))->assertSee(__('Annuel'))->assertSee(__('Prix à venir'))
            ->assertSee(__('Les prix seront bientôt disponibles. Contactez le support pour vous abonner.'))->assertDontSee('>'.__('Payer').'</button>', false);

        $this->plan(3, 13000);
        $this->get(route('subscription.index'))->assertOk()->assertSee(__('Trimestriel'))->assertSee('13 000 XAF')->assertSee(__('Semestriel'))->assertSee(__('Prix à venir'))->assertSee('>'.__('Payer').'</button>', false);
    }

    public function test_only_super_admin_sets_plans_and_methods(): void
    {
        $this->actingAs($this->account());

        $this->get(route('support.subscriptions.settings'))->assertRedirect(route('support.login'));
        $this->put(route('support.subscriptions.plans'), ['plans' => []])->assertRedirect(route('support.login'));
    }

    public function test_super_admin_sets_prices_and_needs_a_price_to_activate(): void
    {
        $this->asSupport();
        $plan = SubscriptionPlan::where('months', 12)->first();

        $this->from(route('support.subscriptions.settings'))->put(route('support.subscriptions.plans'), [
            'plans' => [$plan->id => ['price' => '', 'currency' => 'XAF', 'is_active' => 1]],
        ])->assertSessionHasErrors("plans.{$plan->id}.price");
        $this->assertFalse($plan->fresh()->is_active);

        $this->from(route('support.subscriptions.settings'))->put(route('support.subscriptions.plans'), [
            'plans' => [$plan->id => ['price' => '50000', 'currency' => 'EUR', 'is_active' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(50000, $plan->fresh()->price);

        $this->from(route('support.subscriptions.settings'))->put(route('support.subscriptions.plans'), [
            'plans' => [$plan->id => ['price' => '50000', 'currency' => 'EUR', 'is_active' => 0]],
        ])->assertSessionHas('error', __('Un plan avec un prix mais sans la case Actif reste invisible pour les utilisateurs.'));

        $this->from(route('support.subscriptions.settings'))->put(route('support.subscriptions.plans'), [
            'plans' => [$plan->id => ['price' => '50000', 'currency' => 'EUR', 'is_active' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('EUR', $plan->fresh()->currency);
        $this->assertTrue($plan->fresh()->is_active);

        $this->get(route('support.subscriptions.settings'))->assertOk()->assertSee(__('Annuel'))->assertSee(__('Trimestriel'));
    }

    public function test_orange_and_mtn_are_shown_as_unavailable_and_refused(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->account());

        $this->get(route('subscription.index'))->assertOk()
            ->assertSee(__('Orange Money'))->assertSee(__('MTN Mobile Money'))->assertSee(__('Espèces'))->assertSee(__('Payer'))
            ->assertDontSee(__('Ce mode de paiement n\'est pas disponible pour le moment. Payez en espèces.'));

        foreach (['orange_money', 'mobile_money'] as $method) {
            $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => $method])
                ->assertSessionHasErrors(['method' => __('Ce mode de paiement n\'est pas disponible pour le moment. Payez en espèces.')]);

            // Le message est affiché au retour sur la page, avec le choix conservé.
            $this->from(route('subscription.index'))->followingRedirects()
                ->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => $method])
                ->assertSee(__('Ce mode de paiement n\'est pas disponible pour le moment. Payez en espèces.'));
        }

        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_cash_instructions_set_by_super_admin_are_shown(): void
    {
        $this->plan();
        $user = $this->account();
        $this->asSupport();

        $this->from(route('support.subscriptions.settings'))->put(route('support.subscriptions.methods'), ['cash_instructions' => 'Bureau de Bastos', 'trial_days' => 14])->assertSessionHasNoErrors();

        $this->actingAs($user);
        $this->get(route('subscription.index'))->assertSee('Bureau de Bastos');
    }

    public function test_cash_request_extends_nothing_until_approved(): void
    {
        $plan = $this->plan();
        $this->actingAs($user = $this->account());

        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertNull($user->organisation->fresh()->subscribed_until);
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash'])->assertSessionHas('error');
        $this->assertSame(1, SubscriptionPayment::count());
    }

    public function test_rejection_extends_nothing_and_allows_a_new_request(): void
    {
        $plan = $this->plan();
        $user = $this->account();
        $this->actingAs($user);
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash']);

        $this->asSupport();
        $this->post(route('support.subscriptions.reject', SubscriptionPayment::first()), ['note' => 'Non reçu'])->assertSessionHas('success');

        $this->assertNull($user->organisation->fresh()->subscribed_until);
        $this->assertSame(SubscriptionPayment::REJECTED, SubscriptionPayment::first()->status);

        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash'])->assertSessionHasNoErrors();
    }

    public function test_payments_stay_private_between_organisations(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->account());
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash']);

        $this->actingAs($this->account('b@test.test', 'Bleu Bassin'));

        $this->assertCount(0, $this->get(route('subscription.index'))->assertOk()->original->getData()['payments']);
    }

    public function test_super_admin_sees_payment_history_and_due_date_of_an_account(): void
    {
        $user = $this->account();
        $plan = $this->plan(3, 13000);
        $this->actingAs($user);
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash']);
        $this->asSupport();
        Auth::guard('web')->logout();

        $this->post(route('support.subscriptions.approve', SubscriptionPayment::first()));

        $this->get(route('support.accounts.show', $user->organisation_id))->assertOk()
            ->assertSee(__('Historique des paiements'))->assertSee('13 000 XAF')->assertSee(__('Trimestriel'))->assertSee('15/02/2027');
        $this->get(route('support.accounts.index'))->assertOk()->assertSee(__('Échéance'))->assertSee('15/02/2027');
        $this->get(route('support.subscriptions.index', ['etat' => 'tous']))->assertOk()->assertSee('Eau Alpha')->assertSee('15/02/2027');
    }

    public function test_red_alert_shows_while_unpaid_without_blocking_access(): void
    {
        $user = $this->account();
        $this->actingAs($user);

        // Sans plan actif, l'utilisateur ne peut rien payer : pas d'alerte.
        $this->get(route('tenants.index'))->assertOk()->assertDontSee(__('Votre abonnement n\'est pas payé. Sans paiement, le service prendra bientôt fin.'));

        $this->plan();
        $this->get(route('tenants.index'))->assertOk()->assertSee('flash error', false)->assertSee(__('Votre abonnement n\'est pas payé. Sans paiement, le service prendra bientôt fin.'));

        $user->organisation->forceFill(['subscribed_until' => '2027-03-01'])->save();
        $this->get(route('tenants.index'))->assertOk()->assertDontSee(__('Payer mon abonnement'));

        $user->organisation->forceFill(['subscribed_until' => '2026-11-20'])->save();
        $this->get(route('tenants.index'))->assertOk()->assertSee('flash error', false)->assertSee(__('Votre abonnement se termine le :v1. Sans paiement, le service prendra bientôt fin.', ['v1' => '20/11/2026']));

    }

    public function test_expired_subscription_blocks_everything_except_the_payment(): void
    {
        $plan = $this->plan();
        $user = $this->account();
        $user->organisation->forceFill(['subscribed_until' => '2026-11-14'])->save();
        $this->actingAs($user);

        foreach (['dashboard', 'tenants.index', 'units.index', 'properties.index', 'invoices.index', 'unpaid.index', 'messages.index', 'payments.index', 'settings.edit'] as $route) {
            $this->get(route($route))->assertRedirect(route('subscription.index'));
        }
        $this->post(route('tenants.store'), ['name' => 'Refusé'])->assertRedirect(route('subscription.index'));
        $this->assertSame(0, \App\Models\Tenant::count());

        $page = $this->get(route('subscription.index'));
        $page->assertOk()->assertSee(__('Votre abonnement a expiré le :v1. Effectuez le paiement pour retrouver les fonctionnalités.', ['v1' => '14/11/2026']))
            ->assertSee(__('Choisir un plan'))
            ->assertDontSee(route('tenants.index'), false)
            ->assertDontSee(__('Renseignez vos questions de sécurité'));

        // Une simple demande de paiement ne rouvre rien : il faut que le paiement soit validé.
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->get(route('tenants.index'))->assertRedirect(route('subscription.index'));
        $this->get(route('subscription.index'))->assertOk();

        $this->asSupport();
        $this->post(route('support.subscriptions.approve', SubscriptionPayment::first()))->assertSessionHasNoErrors();
        $this->actingAs($user->fresh());
        Auth::shouldUse('web');
        $this->get(route('tenants.index'))->assertOk();
    }

    public function test_subscription_is_still_open_on_its_last_day(): void
    {
        $user = $this->account();
        $user->organisation->forceFill(['subscribed_until' => '2026-11-15'])->save();

        $this->actingAs($user)->get(route('tenants.index'))->assertOk();
    }

    public function test_expired_account_can_still_replace_a_temporary_password_then_only_pay(): void
    {
        $user = $this->account();
        $user->organisation->forceFill(['subscribed_until' => '2026-11-01'])->save();
        $user->forceFill(['must_change_password' => true])->save();
        $this->actingAs($user);

        $this->get(route('tenants.index'))->assertRedirect(route('settings.edit').'#section-mot-de-passe');
        $this->get(route('settings.edit'))->assertOk();

        $this->from(route('settings.edit'))->put(route('settings.password'), [
            'current_password' => 'secret-pass-1', 'password' => 'nouveau-pass-1', 'password_confirmation' => 'nouveau-pass-1',
        ])->assertSessionHasNoErrors();

        $this->get(route('settings.edit'))->assertRedirect(route('subscription.index'));
    }

    public function test_registration_starts_the_trial_with_the_duration_set_by_super_admin(): void
    {
        $registration = fn (string $email) => [
            'organisation_name' => 'Société '.$email, 'phone_country' => 'CM', 'currency' => 'XAF', 'name' => 'Admin', 'email' => $email,
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
            'security' => [['question' => 'first_school', 'answer' => 'Ecole'], ['question' => 'birth_city', 'answer' => 'Ville'], ['question' => 'first_job', 'answer' => 'Metier']],
        ];

        $this->post('/inscription', $registration('un@test.test'))->assertRedirect(route('welcome'));
        $this->assertSame('2026-11-29', User::where('email', 'un@test.test')->first()->organisation->trial_ends_at->toDateString());
        $this->post(route('logout'));

        $this->asSupport();
        $this->from(route('support.subscriptions.settings'))->put(route('support.subscriptions.methods'), ['trial_days' => 30])->assertSessionHasNoErrors();

        $this->post('/inscription', $registration('deux@test.test'))->assertRedirect(route('welcome'));
        $this->assertSame('2026-12-15', User::where('email', 'deux@test.test')->first()->organisation->trial_ends_at->toDateString());
    }

    public function test_during_the_trial_access_is_open_with_a_red_reminder(): void
    {
        $this->plan();
        $user = $this->account();
        $user->organisation->forceFill(['trial_ends_at' => '2026-11-15'])->save();
        $this->actingAs($user);

        $this->get(route('tenants.index'))->assertOk()->assertSee('flash error', false)
            ->assertSee(__('Essai gratuit jusqu\'au :v1. Sans plan choisi à cette date, l\'accès sera suspendu.', ['v1' => '15/11/2026']));
    }

    public function test_after_the_trial_only_the_subscription_page_stays_open_and_choosing_a_plan_reopens(): void
    {
        $plan = $this->plan();
        $user = $this->account();
        $user->organisation->forceFill(['trial_ends_at' => '2026-11-14'])->save();
        $this->actingAs($user);

        foreach (['dashboard', 'tenants.index', 'units.index', 'invoices.index', 'payments.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('subscription.index'));
        }
        $this->post(route('tenants.store'), ['name' => 'Refusé'])->assertRedirect(route('subscription.index'));
        $this->assertSame(0, \App\Models\Tenant::count());

        $this->get(route('subscription.index'))->assertOk()->assertSee(__('Votre essai gratuit est terminé et votre accès est suspendu. Choisissez un plan pour le retrouver : vos données sont conservées.'));
        $this->get(route('settings.edit'))->assertRedirect(route('subscription.index'));

        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->get(route('tenants.index'))->assertOk()->assertSee(__('Votre essai gratuit est terminé. Payez votre abonnement pour éviter la suspension de l\'accès.'));

        // Demande refusée : l'accès est de nouveau suspendu.
        $this->asSupport();
        $this->post(route('support.subscriptions.reject', SubscriptionPayment::first()));
        $this->get(route('tenants.index'))->assertRedirect(route('subscription.index'));

        // Abonnement accordé : tout est rouvert.
        $this->post(route('support.subscriptions.extend', $user->organisation_id), ['months' => 1, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->actingAs($user->fresh()); // Le test garde le même objet d'une requête à l'autre.
        Auth::shouldUse('web');
        $this->get(route('tenants.index'))->assertOk();
    }

    public function test_no_lock_when_no_plan_can_be_chosen(): void
    {
        $user = $this->account();
        $user->organisation->forceFill(['trial_ends_at' => '2026-11-01'])->save();
        $this->actingAs($user);

        $this->get(route('tenants.index'))->assertOk();
    }

    public function test_cash_needs_no_phone_or_reference(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->account());

        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertSame('cash', SubscriptionPayment::first()->method);
    }

    public function test_user_cannot_choose_an_inactive_plan_or_set_the_price(): void
    {
        $this->actingAs($this->account());
        $inactive = SubscriptionPlan::where('months', 6)->first();

        $this->post(route('subscription.store'), ['plan_id' => $inactive->id, 'method' => 'cash'])->assertSessionHasErrors('plan_id');

        $plan = $this->plan();
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash', 'amount' => 1, 'status' => 'approved'])->assertSessionHasNoErrors();
        $this->assertEquals(5000, SubscriptionPayment::first()->amount);
        $this->assertSame(SubscriptionPayment::PENDING, SubscriptionPayment::first()->status);
    }

    public function test_approval_extends_from_today_then_from_the_end_and_only_once(): void
    {
        $user = $this->account();
        $plan = $this->plan(3, 13000);
        $this->actingAs($user);
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash']);
        $payment = SubscriptionPayment::first();

        $this->asSupport();
        Auth::guard('web')->logout();

        $this->post(route('support.subscriptions.approve', $payment))->assertSessionHas('success');
        $this->assertSame('2027-02-15', $user->organisation->fresh()->subscribed_until->toDateString());
        $this->assertSame(SubscriptionPayment::APPROVED, $payment->fresh()->status);
        $this->assertSame($this->admin->id, $payment->fresh()->handled_by);

        // Validé deux fois : une seule prolongation.
        $this->post(route('support.subscriptions.approve', $payment))->assertSessionHas('error');
        $this->assertSame('2027-02-15', $user->organisation->fresh()->subscribed_until->toDateString());

        // Le temps restant n'est pas perdu.
        $this->post(route('support.subscriptions.extend', $user->organisation_id), ['months' => 1, 'method' => 'cash', 'amount' => 5000, 'currency' => 'XAF'])->assertSessionHasNoErrors();
        $this->assertSame('2027-03-15', $user->organisation->fresh()->subscribed_until->toDateString());
        $this->assertSame(2, SupportAction::where('organisation_id', $user->organisation_id)->count());
    }

    public function test_expired_subscription_restarts_from_today(): void
    {
        $user = $this->account();
        $user->organisation->forceFill(['subscribed_until' => '2026-01-01'])->save();
        $this->asSupport();

        $this->post(route('support.subscriptions.extend', $user->organisation_id), ['months' => 1, 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->assertSame('2026-12-15', $user->organisation->fresh()->subscribed_until->toDateString());
    }

    public function test_only_super_admin_validates_or_extends(): void
    {
        $user = $this->account();
        $plan = $this->plan();
        $this->actingAs($user);
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash']);

        $this->post(route('support.subscriptions.approve', SubscriptionPayment::first()))->assertRedirect(route('support.login'));
        $this->post(route('support.subscriptions.extend', $user->organisation_id), ['months' => 12, 'method' => 'cash'])->assertRedirect(route('support.login'));
        $this->assertNull($user->organisation->fresh()->subscribed_until);
    }

    public function test_support_pages_answer_on_every_sort(): void
    {
        $user = $this->account();
        $plan = $this->plan();
        $this->actingAs($user);
        $this->post(route('subscription.store'), ['plan_id' => $plan->id, 'method' => 'cash']);
        $this->asSupport();

        foreach (['date', 'organisation', 'months', 'amount', 'method', 'end', 'status'] as $column) {
            $this->get(route('support.subscriptions.index', ['paiements_tri' => $column, 'paiements_ordre' => 'desc']))->assertOk();
        }

        foreach (['date', 'months', 'amount', 'method', 'status', 'end'] as $column) {
            $this->get(route('subscription.index', ['abonnements_tri' => $column, 'abonnements_ordre' => 'asc']))->assertOk();
        }

        $this->get(route('support.accounts.index', ['comptes_tri' => 'subscription', 'comptes_ordre' => 'asc']))->assertOk();
        $this->get(route('support.accounts.show', $user->organisation_id))->assertOk()->assertSee(__('Prolonger l\'abonnement'));
    }
}
