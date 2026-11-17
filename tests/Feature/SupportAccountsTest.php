<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Organisation;
use App\Models\PasswordSupportRequest;
use App\Models\SupportAction;
use App\Models\SupportAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SupportAccountsTest extends TestCase
{
    use RefreshDatabase;

    private SupportAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = SupportAdmin::create(['name' => 'Agent Support', 'email' => 'support@tower.test', 'password' => 'support-pass-1']);
    }

    private function account(string $name = 'Eau Alpha', string $email = 'a@test.test'): User
    {
        $organisation = Organisation::create([
            'name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF', 'phone' => '677000000',
        ]);

        return $organisation->users()->create(['name' => 'Propriétaire '.$name, 'email' => $email, 'password' => 'secret-pass-1']);
    }

    private function asSupport(): void
    {
        $this->actingAs($this->admin, 'support');
        Auth::shouldUse('web');
    }

    private function login(User $user, string $password = 'secret-pass-1')
    {
        return $this->post('/connexion', ['organisation_code' => $user->organisation->code, 'email' => $user->email, 'password' => $password]);
    }

    public function test_only_support_can_open_accounts(): void
    {
        $user = $this->account();

        $this->get(route('support.accounts.index'))->assertRedirect(route('support.login'));
        $this->post(route('support.accounts.suspend', $user->organisation_id), ['reason' => 'x'])->assertRedirect(route('support.login'));

        $this->actingAs($user);
        $this->get(route('support.accounts.index'))->assertRedirect(route('support.login'));
        $this->get(route('support.accounts.show', $user->organisation_id))->assertRedirect(route('support.login'));
        $this->post(route('support.accounts.reset', [$user->organisation_id, $user->id]), ['identity_verified' => 1])->assertRedirect(route('support.login'));
        $this->assertNull($user->organisation->fresh()->suspended_at);
        $this->assertTrue(Hash::check('secret-pass-1', $user->fresh()->password));
    }

    public function test_list_shows_all_accounts_with_totals_search_and_ten_lines(): void
    {
        $alpha = $this->account('Eau Alpha', 'alpha@test.test');
        $this->account('Bleu Bassin', 'bleu@test.test');
        foreach (range(1, 11) as $index) {
            $this->account('Compte '.$index, "c$index@test.test");
        }

        $this->actingAs($alpha);
        Tenant::create(['name' => 'Client secret']);
        Auth::guard('web')->logout();

        $this->asSupport();

        $page = $this->get(route('support.accounts.index'))->assertOk()->assertSee('1 / 2')->assertDontSee('Client secret');
        $this->assertCount(13, $page->original->getData()['accounts']);

        $found = $this->get(route('support.accounts.index', ['q' => 'bleu@']))->assertOk()->original->getData()['accounts'];
        $this->assertCount(1, $found);
        $this->assertSame('Bleu Bassin', $found->items()[0]->name);

        $this->assertCount(13, $this->get(route('support.accounts.index', ['q' => 'test.test']))->original->getData()['accounts']);
        $this->assertCount(0, $this->get(route('support.accounts.index', ['q' => '%%%']))->original->getData()['accounts']);

        $sorted = $this->get(route('support.accounts.index', ['comptes_tri' => 'tenants', 'comptes_ordre' => 'desc']))->assertOk()->original->getData()['accounts'];
        $this->assertSame('Eau Alpha', $sorted->items()[0]->name);
        $this->assertSame(1, (int) $sorted->items()[0]->tenants_total);
    }

    public function test_every_sort_column_answers(): void
    {
        $this->account();
        $this->asSupport();

        foreach (['name', 'code', 'owner', 'tenants', 'units', 'invoices', 'status', 'created'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $this->get(route('support.accounts.index', ['comptes_tri' => $column, 'comptes_ordre' => $direction]))->assertOk();
            }
        }
    }

    public function test_totals_are_not_limited_when_the_same_browser_is_also_a_distributor(): void
    {
        $first = $this->account('Eau Alpha', 'alpha@test.test');
        $second = $this->account('Bleu Bassin', 'bleu@test.test');

        $this->actingAs($second);
        Tenant::create(['name' => 'Client Bleu']);

        $this->actingAs($this->admin, 'support');
        Auth::shouldUse('web');
        $this->actingAs($first);

        $accounts = collect($this->get(route('support.accounts.index'))->assertOk()->original->getData()['accounts']->items());

        $this->assertSame(1, (int) $accounts->firstWhere('name', 'Bleu Bassin')->tenants_total);
    }

    public function test_sheet_shows_account_without_secrets(): void
    {
        $user = $this->account();
        $user->securityAnswers()->create(['question' => 'first_job', 'answer' => Hash::make('plombier')]);
        $this->asSupport();

        $this->get(route('support.accounts.show', $user->organisation_id))->assertOk()
            ->assertSee($user->email)->assertSee('677000000')->assertSee(__('Renseignées'))
            ->assertDontSee($user->password)->assertDontSee('plombier')->assertDontSee('first_job');

        $this->get(route('support.accounts.show', 99999))->assertNotFound();
    }

    public function test_reset_needs_identity_check_and_is_logged_and_settles_pending_request(): void
    {
        $user = $this->account();
        $pending = PasswordSupportRequest::create(['user_id' => $user->id, 'organisation_code' => $user->organisation->code, 'login' => $user->email, 'contact' => '677']);
        DB::table('sessions')->insert(['id' => 'autre', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->asSupport();

        $this->from(route('support.accounts.show', $user->organisation_id))
            ->post(route('support.accounts.reset', [$user->organisation_id, $user->id]))->assertSessionHasErrors('identity_verified');
        $this->assertTrue(Hash::check('secret-pass-1', $user->fresh()->password));

        $response = $this->post(route('support.accounts.reset', [$user->organisation_id, $user->id]), ['identity_verified' => 1])
            ->assertRedirect(route('support.accounts.show', $user->organisation_id));
        $temporary = $response->getSession()->get('temporary_password');

        $this->assertTrue(Hash::check($temporary, $user->fresh()->password));
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(PasswordSupportRequest::RESET, $pending->fresh()->status);
        $this->assertSame($this->admin->id, $pending->fresh()->handled_by);

        $this->get(route('support.accounts.show', $user->organisation_id))->assertSee($temporary)->assertSee('Agent Support');
        $this->get(route('support.accounts.show', $user->organisation_id))->assertDontSee($temporary);

        $action = SupportAction::where('organisation_id', $user->organisation_id)->first();
        $this->assertSame(SupportAction::PASSWORD_RESET, $action->action);
        $this->assertStringNotContainsString($temporary, json_encode($action->toArray()));
    }

    public function test_cannot_reset_a_user_through_another_organisation(): void
    {
        $first = $this->account('Eau Alpha', 'alpha@test.test');
        $other = $this->account('Bleu Bassin', 'bleu@test.test');
        $this->asSupport();

        $this->post(route('support.accounts.reset', [$first->organisation_id, $other->id]), ['identity_verified' => 1])->assertNotFound();
        $this->assertTrue(Hash::check('secret-pass-1', $other->fresh()->password));
    }

    public function test_suspension_blocks_login_keeps_data_and_can_be_undone(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        Tenant::create(['name' => 'Client gardé']);
        Auth::guard('web')->logout();
        DB::table('sessions')->insert(['id' => 'autre', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->asSupport();

        $this->from(route('support.accounts.show', $user->organisation_id))
            ->post(route('support.accounts.suspend', $user->organisation_id), [])->assertSessionHasErrors('reason');
        $this->assertNull($user->organisation->fresh()->suspended_at);

        $this->post(route('support.accounts.suspend', $user->organisation_id), ['reason' => 'Impayé abonnement'])->assertSessionHas('success');
        $this->assertNotNull($user->organisation->fresh()->suspended_at);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->get(route('support.accounts.show', $user->organisation_id))->assertSee('Impayé abonnement');

        $this->login($user)->assertSessionHasErrors(['email' => __('Ce compte est suspendu. Contactez le support.')]);
        $this->assertGuest('web');

        $this->post(route('support.accounts.reactivate', $user->organisation_id))->assertSessionHas('success');
        $this->assertNull($user->organisation->fresh()->suspended_at);
        $this->assertNull($user->organisation->fresh()->suspension_reason);
        $this->assertSame(2, SupportAction::where('organisation_id', $user->organisation_id)->count());

        $this->login($user)->assertRedirect(route('dashboard'));
        $this->get(route('tenants.index'))->assertOk()->assertSee('Client gardé');
    }

    public function test_wrong_password_on_a_suspended_account_does_not_reveal_the_suspension(): void
    {
        $user = $this->account();
        $user->organisation->forceFill(['suspended_at' => now()])->save();

        $this->login($user, 'faux-mot-de-passe')->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    public function test_open_session_is_closed_when_the_account_is_suspended(): void
    {
        $user = $this->account();
        $this->actingAs($user);
        $this->get(route('tenants.index'))->assertOk();

        $user->organisation->forceFill(['suspended_at' => now()])->save();
        Auth::forgetGuards();
        $this->actingAs($user->fresh());

        $this->get(route('tenants.index'))->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest('web');
    }
}
