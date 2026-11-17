<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\PasswordSupportRequest;
use App\Models\SupportAdmin;
use App\Models\User;
use App\Services\PasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $entries = [
        ['question' => 'first_school', 'answer' => 'École Saint-Joseph'],
        ['question' => 'birth_city', 'answer' => 'Yaoundé'],
        ['question' => 'first_job', 'answer' => 'Plombier'],
    ];

    private function account(string $email = 'a@test.test', string $name = 'Eau Alpha', bool $withAnswers = true): User
    {
        $organisation = Organisation::create([
            'name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF', 'phone' => '677000000',
        ]);
        $user = $organisation->users()->create(['name' => 'Propriétaire', 'email' => $email, 'password' => 'secret-pass-1']);

        if ($withAnswers) {
            app(PasswordRecovery::class)->saveAnswers($user, $this->entries);
        }

        return $user;
    }

    private function admin(): SupportAdmin
    {
        return SupportAdmin::create(['name' => 'Support', 'email' => 'support@tower.test', 'password' => 'support-pass-1']);
    }

    private function goodAnswers(): array
    {
        return ['first_school' => 'ecole saint-joseph', 'birth_city' => '  YAOUNDE ', 'first_job' => 'plombier'];
    }

    private function startRecovery(User $user): void
    {
        $this->post(route('password.lookup'), ['organisation_code' => $user->organisation->code, 'email' => $user->email])
            ->assertRedirect(route('password.questions'));
    }

    public function test_no_email_is_sent_and_old_routes_are_gone(): void
    {
        Notification::fake();
        Mail::fake();
        $user = $this->account();

        $this->startRecovery($user);
        $this->post(route('password.verify'), ['answers' => $this->goodAnswers()]);

        Notification::assertNothingSent();
        Mail::assertNothingSent();
        $this->get('/reinitialiser-mot-de-passe/abc')->assertNotFound();
    }

    public function test_answers_are_hashed_and_never_shown(): void
    {
        $user = $this->account();

        $this->assertStringNotContainsString('Plombier', $user->securityAnswers()->pluck('answer')->implode(' '));
        $this->assertStringNotContainsString('plombier', $user->securityAnswers()->pluck('answer')->implode(' '));

        $this->startRecovery($user);
        $this->get(route('password.questions'))->assertOk()
            ->assertSee(__(config('security_questions.first_school')))->assertDontSee('Plombier');
    }

    public function test_matching_answers_allow_a_new_password_and_free_the_blocked_session(): void
    {
        $user = $this->account();
        DB::table('sessions')->insert(['id' => 'autre-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $pending = PasswordSupportRequest::create(['user_id' => $user->id, 'organisation_code' => $user->organisation->code, 'login' => $user->email, 'contact' => '677']);

        $this->get(route('password.new'))->assertRedirect(route('password.request'));

        $this->startRecovery($user);
        $this->post(route('password.verify'), ['answers' => $this->goodAnswers()])->assertRedirect(route('password.new'));
        $this->get(route('password.new'))->assertOk();
        $this->post(route('password.update'), ['password' => 'nouveau-pass-2', 'password_confirmation' => 'nouveau-pass-2'])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('nouveau-pass-2', $user->fresh()->password));
        $this->assertSame(PasswordSupportRequest::SELF_SERVICE, $pending->fresh()->status);

        // L'autorisation ne sert qu'une fois.
        $this->get(route('password.new'))->assertRedirect(route('password.request'));

        $this->post('/connexion', ['organisation_code' => $user->organisation->code, 'email' => $user->email, 'password' => 'nouveau-pass-2'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_one_wrong_answer_refuses_and_too_many_attempts_lead_to_support(): void
    {
        $user = $this->account();
        $this->startRecovery($user);

        $wrong = ['answers' => ['birth_city' => 'Douala'] + $this->goodAnswers()];

        foreach (range(1, 5) as $attempt) {
            $this->from(route('password.questions'))->post(route('password.verify'), $wrong)
                ->assertRedirect(route('password.questions'))->assertSessionHas('error');
        }

        $this->get(route('password.new'))->assertRedirect(route('password.request'));

        // Bloqué même avec les bonnes réponses, le temps du délai.
        $this->post(route('password.verify'), ['answers' => $this->goodAnswers()])->assertRedirect(route('support.request'));
        $this->assertTrue(Hash::check('secret-pass-1', $user->fresh()->password));
    }

    public function test_unknown_account_and_account_without_questions_look_the_same_and_never_pass(): void
    {
        $without = $this->account('b@test.test', 'Bleu Bassin', withAnswers: false);

        foreach ([[$without->organisation->code, $without->email], ['XXX-999', 'inconnu@test.test']] as [$code, $login]) {
            $this->post(route('password.lookup'), ['organisation_code' => $code, 'email' => $login])->assertRedirect(route('password.questions'));
            $page = $this->get(route('password.questions'))->assertOk();
            $this->assertSame(3, substr_count($page->getContent(), 'name="answers['));

            $this->from(route('password.questions'))->post(route('password.verify'), ['answers' => ['x' => 'y']])
                ->assertRedirect(route('password.questions'))->assertSessionHas('error');
            $this->get(route('password.new'))->assertRedirect(route('password.request'));
        }
    }

    public function test_questions_of_a_real_account_do_not_change_and_another_organisation_does_not_match(): void
    {
        $user = $this->account();
        $this->account('c@test.test', 'Clair Eau');

        // Bon login, mauvais code : aucune correspondance.
        $this->post(route('password.lookup'), ['organisation_code' => 'CLE-001', 'email' => $user->email]);
        $this->from(route('password.questions'))->post(route('password.verify'), ['answers' => $this->goodAnswers()])->assertSessionHas('error');
        $this->assertTrue(Hash::check('secret-pass-1', $user->fresh()->password));
    }

    public function test_registration_saves_the_questions(): void
    {
        $this->post('/inscription', [
            'organisation_name' => 'Société des Eaux', 'phone_country' => 'CM', 'currency' => 'XAF', 'name' => 'Admin', 'email' => 'admin@eaux.test',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'security' => $this->entries,
        ])->assertRedirect(route('welcome'));

        $this->assertSame(3, User::where('email', 'admin@eaux.test')->first()->securityAnswers()->count());
    }

    public function test_registration_needs_three_different_questions(): void
    {
        $same = $this->entries;
        $same[1]['question'] = 'first_school';

        $this->post('/inscription', [
            'organisation_name' => 'Société', 'currency' => 'XAF', 'name' => 'Admin', 'email' => 'x@eaux.test',
            'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'security' => $same,
        ])->assertSessionHasErrors('security.0.question');

        $this->assertSame(0, User::count());
    }

    public function test_settings_change_questions_with_current_password_and_warn_when_missing(): void
    {
        $user = $this->account(withAnswers: false);
        $this->actingAs($user);

        $this->get(route('tenants.index'))->assertSee(__('Renseignez vos questions de sécurité'));

        $this->from(route('settings.edit'))->put(route('settings.security'), ['security_current_password' => 'faux', 'security' => $this->entries])
            ->assertSessionHasErrorsIn('security', 'security_current_password');
        $this->assertSame(0, $user->securityAnswers()->count());

        $this->from(route('settings.edit'))->put(route('settings.security'), ['security_current_password' => 'secret-pass-1', 'security' => $this->entries])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $user->securityAnswers()->count());

        $this->get(route('tenants.index'))->assertDontSee(__('Renseignez vos questions de sécurité'));
    }

    public function test_support_request_is_recorded_once_and_does_not_reveal_accounts(): void
    {
        $user = $this->account();
        $data = ['organisation_code' => strtolower($user->organisation->code), 'email' => strtoupper($user->email), 'contact' => '677111222', 'message' => 'Perdu'];

        $known = $this->post(route('support.request.store'), $data)->assertRedirect(route('login'));
        $this->post(route('support.request.store'), ['contact' => '699'] + $data)->assertRedirect(route('login'));
        $unknown = $this->post(route('support.request.store'), ['organisation_code' => 'XXX-1', 'email' => 'x@y.test', 'contact' => '1'])->assertRedirect(route('login'));

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        $this->assertSame(2, PasswordSupportRequest::count());
        $this->assertSame($user->id, PasswordSupportRequest::where('login', $user->email)->first()->user_id);
        $this->assertNull(PasswordSupportRequest::where('login', 'x@y.test')->first()->user_id);
    }

    public function test_support_pages_are_only_for_support_accounts(): void
    {
        $user = $this->account();
        $request = PasswordSupportRequest::create(['user_id' => $user->id, 'organisation_code' => $user->organisation->code, 'login' => $user->email, 'contact' => '677']);

        $this->get(route('support.requests.index'))->assertRedirect(route('support.login'));

        // Un distributeur connecté n'y a pas accès.
        $this->actingAs($user);
        $this->get(route('support.requests.index'))->assertRedirect(route('support.login'));
        $this->post(route('support.requests.reset', $request), ['identity_verified' => 1])->assertRedirect(route('support.login'));
        $this->assertTrue(Hash::check('secret-pass-1', $user->fresh()->password));
    }

    public function test_support_login_and_logout(): void
    {
        $this->admin();

        $this->post(route('support.login'), ['email' => 'support@tower.test', 'password' => 'faux'])->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('support')->check());

        $this->post(route('support.login'), ['email' => 'support@tower.test', 'password' => 'support-pass-1'])->assertRedirect(route('support.requests.index'));
        $this->assertTrue(Auth::guard('support')->check());
        $this->assertGuest('web');

        $this->post(route('support.logout'))->assertRedirect(route('support.login'));
        $this->assertFalse(Auth::guard('support')->check());
    }

    public function test_support_resets_with_identity_check_and_temporary_password_must_be_changed(): void
    {
        $user = $this->account();
        DB::table('sessions')->insert(['id' => 'autre-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $request = PasswordSupportRequest::create(['user_id' => $user->id, 'organisation_code' => $user->organisation->code, 'login' => $user->email, 'contact' => '677']);
        $admin = $this->admin();

        $this->actingAs($admin, 'support');
        $this->get(route('support.requests.index'))->assertOk()->assertSee($user->email)->assertSee('Eau Alpha');
        $this->get(route('support.requests.show', $request))->assertOk()->assertSee('677000000')->assertDontSee('Plombier');

        $this->from(route('support.requests.show', $request))->post(route('support.requests.reset', $request))->assertSessionHasErrors('identity_verified');
        $this->assertTrue(Hash::check('secret-pass-1', $user->fresh()->password));

        $response = $this->post(route('support.requests.reset', $request), ['identity_verified' => 1])->assertRedirect(route('support.requests.show', $request));
        $temporary = $response->getSession()->get('temporary_password');

        $this->assertNotEmpty($temporary);
        $this->assertTrue(Hash::check($temporary, $user->fresh()->password));
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(PasswordSupportRequest::RESET, $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->handled_by);
        $this->assertStringNotContainsString($temporary, json_encode($request->fresh()->toArray()));

        $this->get(route('support.requests.show', $request))->assertSee($temporary);
        $this->get(route('support.requests.show', $request))->assertDontSee($temporary);

        // Une demande traitée ne sert pas deux fois.
        $this->post(route('support.requests.reset', $request), ['identity_verified' => 1])->assertSessionHas('error');
        $this->assertTrue(Hash::check($temporary, $user->fresh()->password));

        // Le propriétaire doit remplacer le mot de passe temporaire avant tout le reste.
        Auth::shouldUse('web'); // actingAs a changé la garde par défaut, pour ce test seulement.
        $this->post(route('support.logout'));
        $this->post('/connexion', ['organisation_code' => $user->organisation->code, 'email' => $user->email, 'password' => $temporary]);
        $this->get(route('tenants.index'))->assertRedirect(route('settings.edit').'#section-mot-de-passe');
        $this->get(route('settings.edit'))->assertOk();

        $this->from(route('settings.edit'))->put(route('settings.password'), [
            'current_password' => $temporary, 'password' => 'definitif-pass-3', 'password_confirmation' => 'definitif-pass-3',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get(route('tenants.index'))->assertOk();
    }


    public function test_support_session_does_not_take_the_identity_of_a_distributor(): void
    {
        $admin = $this->admin();

        $this->post(route('support.login'), ['email' => $admin->email, 'password' => 'support-pass-1']);
        $this->get(route('support.requests.index'))->assertOk();

        // La session est enregistrée au nom de la garde par défaut : elle doit rester celle des distributeurs.
        $this->assertSame('web', Auth::getDefaultDriver());
        $this->assertNull(Auth::guard('web')->id());
    }

    public function test_request_without_account_cannot_be_reset_and_can_be_closed(): void
    {
        $request = PasswordSupportRequest::create(['organisation_code' => 'XXX-1', 'login' => 'x@y.test', 'contact' => '1']);
        $this->actingAs($this->admin(), 'support');

        $this->get(route('support.requests.show', $request))->assertOk()->assertDontSee(__('Générer un mot de passe temporaire'));
        $this->post(route('support.requests.reset', $request), ['identity_verified' => 1])->assertSessionHas('error');

        $this->post(route('support.requests.close', $request), ['note' => 'Inconnu'])->assertRedirect(route('support.requests.index'));
        $this->assertSame(PasswordSupportRequest::CLOSED, $request->fresh()->status);
        $this->get(route('support.requests.index'))->assertDontSee('x@y.test');
        $this->get(route('support.requests.index', ['etat' => 'toutes']))->assertSee('x@y.test');
    }

    public function test_support_account_is_created_by_command(): void
    {
        $this->artisan('support:admin', ['email' => 'Chef@Tower.test', '--name' => 'Chef'])
            ->expectsQuestion('Mot de passe', 'long-mot-de-passe')
            ->expectsQuestion('Confirmer le mot de passe', 'long-mot-de-passe')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('long-mot-de-passe', SupportAdmin::where('email', 'chef@tower.test')->first()->password));

        $this->artisan('support:admin', ['email' => 'autre@tower.test'])
            ->expectsQuestion('Mot de passe', 'court')
            ->expectsQuestion('Confirmer le mot de passe', 'court')
            ->assertFailed();
    }
}
