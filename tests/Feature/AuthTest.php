<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return $overrides + [
            'organisation_name' => 'Société des Eaux du Nord',
            'phone_country' => 'CM',
            'currency' => 'XAF',
            'name' => 'Admin Nord',
            'email' => 'admin@nord.test',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
            'security' => [
                ['question' => 'first_school', 'answer' => 'Saint-Joseph'],
                ['question' => 'birth_city', 'answer' => 'Yaoundé'],
                ['question' => 'first_job', 'answer' => 'Plombier'],
            ],
        ];
    }

    private function account(string $email = 'a@test.test', string $name = 'Eau Alpha'): User
    {
        $organisation = Organisation::create([
            'name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF',
        ]);

        return $organisation->users()->create(['name' => 'A', 'email' => $email, 'password' => 'secret-pass-1']);
    }

    public function test_registration_creates_code_and_credentials_file(): void
    {
        $this->post('/inscription', $this->registration())->assertRedirect(route('welcome'));

        $organisation = Organisation::first();
        $this->assertSame('SDE-001', $organisation->code);
        $this->assertAuthenticated();
        $this->assertNotSame('secret-pass-1', User::first()->password);

        $this->get(route('welcome'))->assertOk()->assertSee('SDE-001');

        $file = $this->get(route('welcome.download'));
        $file->assertOk()->assertDownload('identifiants-SDE-001.txt');
        $body = $file->getContent();
        $this->assertStringContainsString('SDE-001', $body);
        $this->assertStringContainsString('admin@nord.test', $body);
        $this->assertStringContainsString('secret-pass-1', $body);
    }

    public function test_plain_password_is_not_stored_in_session_and_file_ends_after_finish(): void
    {
        $this->post('/inscription', $this->registration());
        $this->assertStringNotContainsString('secret-pass-1', serialize(session()->all()));

        $this->post(route('welcome.finish'))->assertRedirect(route('dashboard'));
        $this->get(route('welcome.download'))->assertRedirect(route('dashboard'));
    }

    public function test_credentials_file_expires(): void
    {
        $this->post('/inscription', $this->registration());
        $this->travel(16)->minutes();
        $this->get(route('welcome.download'))->assertRedirect(route('dashboard'));
    }

    public function test_codes_are_unique_for_similar_names(): void
    {
        $this->account('a@t.test', 'Eau Alpha');
        $second = $this->account('b@t.test', 'Eau Atlantique');
        $this->assertSame('EAX-002', $second->organisation->code);
    }

    public function test_email_must_be_unique(): void
    {
        $this->account('admin@nord.test');
        $this->post('/inscription', $this->registration(['email' => 'ADMIN@nord.test']))->assertSessionHasErrors('email');
    }

    public function test_login_requires_all_three_parameters(): void
    {
        $user = $this->account();
        $code = $user->organisation->code;

        $this->post('/connexion', ['email' => $user->email, 'password' => 'secret-pass-1'])->assertSessionHasErrors('organisation_code');
        $this->post('/connexion', ['organisation_code' => 'NOPE-1', 'email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertSessionHasErrors(['email' => 'Code organisation, login ou mot de passe incorrect.']);
        $this->post('/connexion', ['organisation_code' => $code, 'email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/connexion', ['organisation_code' => ' '.strtolower($code).' ', 'email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_another_organisation_code(): void
    {
        $user = $this->account('a@t.test', 'Eau Alpha');
        $other = $this->account('b@t.test', 'Bleu Bassin');

        $this->post('/connexion', ['organisation_code' => $other->organisation->code, 'email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // Deux appareils à la fois sont acceptés ; le troisième est refusé.
    public function test_a_second_device_is_allowed_and_a_third_is_refused(): void
    {
        $user = $this->account();
        $login = ['organisation_code' => $user->organisation->code, 'email' => $user->email, 'password' => 'secret-pass-1'];
        DB::table('sessions')->insert([
            'id' => 'telephone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);

        $this->post('/connexion', $login)->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);

        Auth::logout();
        $this->flushSession();
        DB::table('sessions')->insert([
            'id' => 'ordinateur', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);

        $this->post('/connexion', $login)
            ->assertSessionHasErrors(['email' => 'Ce compte est déjà connecté sur 2 appareils. Déconnectez-vous de l\'un d\'eux pour continuer.']);
        $this->assertGuest();
    }

    // Une session expirée ne compte pas.
    public function test_expired_sessions_do_not_count(): void
    {
        $user = $this->account();
        foreach (['ancien-1', 'ancien-2'] as $id) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->subMinutes(config('session.lifetime') + 5)->timestamp,
            ]);
        }

        $this->post('/connexion', ['organisation_code' => $user->organisation->code, 'email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

}
