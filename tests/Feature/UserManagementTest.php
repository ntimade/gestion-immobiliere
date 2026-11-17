<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->owner('SIC');
        $this->actingAs($this->admin);
    }

    private function owner(string $name): User
    {
        $organisation = Organisation::create(['name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF']);

        return $organisation->users()->create(['name' => 'Patron '.$name, 'email' => strtolower($name).'@t.test', 'password' => 'secret-pass-1']);
    }

    private function member(string $role = User::MANAGER, string $email = 'agent@t.test', ?User $owner = null): User
    {
        $member = ($owner ?? $this->admin)->organisation->users()->create(['name' => 'Agent', 'email' => $email, 'password' => 'secret-pass-1']);
        $member->forceFill(['role' => $role])->save();

        return $member;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Awa', 'email' => 'Awa@T.test ', 'role' => 'manager', 'password' => 'provisoire-1', 'password_confirmation' => 'provisoire-1'];
    }

    public function test_first_account_is_administrator_and_sees_the_menu(): void
    {
        $this->assertTrue($this->admin->fresh()->isAdmin());
        $this->get(route('dashboard'))->assertOk()->assertSee(route('users.index'), false);
    }

    public function test_admin_adds_a_user_to_the_company_with_a_temporary_password(): void
    {
        $this->post(route('users.store'), $this->payload())->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $member = User::where('email', 'awa@t.test')->firstOrFail();
        $this->assertSame($this->admin->organisation_id, $member->organisation_id);
        $this->assertSame(User::MANAGER, $member->role);
        $this->assertTrue($member->must_change_password);
        $this->assertTrue(Hash::check('provisoire-1', $member->password));

        $this->get(route('users.index'))->assertOk()->assertSee('awa@t.test')->assertSee('Gestionnaire')->assertSee('Provisoire')->assertSee($this->admin->organisation->code);
    }

    public function test_new_user_signs_in_with_the_company_code_and_must_change_the_password(): void
    {
        $this->post(route('users.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login'), ['organisation_code' => $this->admin->organisation->code, 'email' => 'awa@t.test', 'password' => 'provisoire-1'])->assertRedirect();
        $this->assertAuthenticated();

        $this->get(route('tenants.index'))->assertRedirect(route('settings.edit').'#section-mot-de-passe');

        $this->put(route('settings.password'), ['current_password' => 'provisoire-1', 'password' => 'mon-secret-22', 'password_confirmation' => 'mon-secret-22'])->assertSessionHasNoErrors();
        $this->get(route('tenants.index'))->assertOk();
    }

    public function test_form_is_validated(): void
    {
        $this->post(route('users.store'), $this->payload(['email' => 'pas-un-email']))->assertSessionHasErrors('email');
        $this->post(route('users.store'), $this->payload(['role' => 'patron']))->assertSessionHasErrors('role');
        $this->post(route('users.store'), $this->payload(['password' => 'court', 'password_confirmation' => 'court']))->assertSessionHasErrors('password');
        $this->post(route('users.store'), $this->payload(['password_confirmation' => 'different-1']))->assertSessionHasErrors('password');
        $this->post(route('users.store'), $this->payload(['email' => 'sic@t.test']))->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_role_cannot_be_forced_on_the_user_email_used_elsewhere(): void
    {
        $this->owner('Autre');

        $this->post(route('users.store'), $this->payload(['email' => 'autre@t.test']))->assertSessionHasErrors('email');
    }

    public function test_manager_works_daily_but_cannot_manage_the_company(): void
    {
        $this->actingAs($this->member());

        foreach (['dashboard', 'properties.index', 'tenants.index', 'payments.index', 'messages.index', 'settings.edit', 'subscription.index'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
        $this->post(route('users.store'), $this->payload())->assertForbidden();
        $this->put(route('settings.update'), ['name' => 'Piraté', 'currency' => 'XAF', 'reminder_days' => 3])->assertForbidden();
        $this->post(route('settings.logo.update'))->assertForbidden();
        $this->delete(route('settings.logo.destroy'))->assertForbidden();
        $this->post(route('subscription.store'), ['plan_id' => 1, 'method' => 'cash'])->assertForbidden();

        $this->assertSame('SIC', $this->admin->organisation->fresh()->name);
        $this->get(route('dashboard'))->assertDontSee(route('users.index'), false)->assertSee('Mon compte');
        $this->get(route('settings.edit'))->assertSee('Changer mon mot de passe')->assertDontSee('Compte et facturation');
        $this->get(route('subscription.index'))->assertSee('Seul un administrateur');
    }

    public function test_manager_still_changes_his_own_password(): void
    {
        $this->actingAs($this->member());

        $this->put(route('settings.password'), ['current_password' => 'secret-pass-1', 'password' => 'mon-secret-22', 'password_confirmation' => 'mon-secret-22'])->assertSessionHasNoErrors();
    }

    public function test_admin_edits_a_user_and_a_new_password_is_temporary(): void
    {
        $member = $this->member();

        $this->put(route('users.update', $member->id), ['name' => 'Nouveau', 'email' => 'nouveau@t.test', 'role' => 'admin'])->assertSessionHasNoErrors();
        $member->refresh();
        $this->assertSame('Nouveau', $member->name);
        $this->assertTrue($member->isAdmin());
        $this->assertTrue(Hash::check('secret-pass-1', $member->password));
        $this->assertFalse((bool) $member->must_change_password);

        $this->put(route('users.update', $member->id), ['name' => 'Nouveau', 'email' => 'nouveau@t.test', 'role' => 'manager', 'password' => 'provisoire-2', 'password_confirmation' => 'provisoire-2'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('provisoire-2', $member->fresh()->password));
        $this->assertTrue($member->fresh()->must_change_password);
    }

    public function test_admin_cannot_change_his_own_role_or_delete_himself(): void
    {
        $this->put(route('users.update', $this->admin->id), ['name' => 'Patron', 'email' => 'sic@t.test', 'role' => 'manager'])->assertSessionHasNoErrors();
        $this->assertTrue($this->admin->fresh()->isAdmin());
        $this->assertSame('Patron', $this->admin->fresh()->name);

        $this->delete(route('users.destroy', $this->admin->id))->assertSessionHas('error');
        $this->assertNotNull($this->admin->fresh());
    }

    public function test_deleting_a_user_removes_his_access_and_keeps_his_records(): void
    {
        $member = $this->member();

        $this->delete(route('users.destroy', $member->id))->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $this->assertNull(User::find($member->id));
    }

    public function test_another_admin_can_be_removed_while_one_remains(): void
    {
        $second = $this->member(User::ADMIN);

        $this->delete(route('users.destroy', $second->id))->assertSessionHasNoErrors();

        $this->assertNull(User::find($second->id));
        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_users_of_another_company_stay_out_of_reach(): void
    {
        $other = $this->owner('Autre');
        $foreign = $this->member(User::MANAGER, 'etranger@t.test', $other);

        $this->get(route('users.index'))->assertOk()->assertDontSee('etranger@t.test')->assertDontSee('autre@t.test');
        $this->get(route('users.edit', $foreign->id))->assertNotFound();
        $this->put(route('users.update', $foreign->id), ['name' => 'Volé', 'email' => 'vole@t.test', 'role' => 'admin'])->assertNotFound();
        $this->delete(route('users.destroy', $foreign->id))->assertNotFound();

        $this->assertSame('Agent', $foreign->fresh()->name);
        $this->assertSame(User::MANAGER, $foreign->fresh()->role);
    }

    public function test_locked_account_shows_a_manager_only_the_subscription_notice(): void
    {
        SubscriptionPlan::query()->delete();
        $this->admin->organisation->forceFill(['subscribed_until' => now()->subDay()->toDateString()])->save();
        $this->actingAs($this->member()->fresh());

        $this->get(route('tenants.index'))->assertRedirect(route('subscription.index'));
        $this->get(route('subscription.index'))->assertOk()->assertSee('Seul un administrateur');
    }
}
