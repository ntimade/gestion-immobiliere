<?php

namespace Tests\Feature;

use App\Models\SupportAdmin;
use Database\Seeders\SupportAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SupportAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_an_account_that_signs_in_without_organisation_code(): void
    {
        $this->seed(SupportAdminSeeder::class);

        $admin = SupportAdmin::where('email', 'superadmin@gestion-immobiliere.local')->firstOrFail();
        $this->assertNotEmpty($admin->password);

        // Le mot de passe n'est connu que de la sortie du seeder : on en pose un pour essayer la connexion.
        $admin->update(['password' => 'mot-de-passe-essai']);

        $this->post(route('support.login'), ['email' => $admin->email, 'password' => 'mot-de-passe-essai'])
            ->assertRedirect(route('support.requests.index'));
        $this->assertTrue(Auth::guard('support')->check());
    }

    public function test_running_it_again_keeps_the_existing_password(): void
    {
        $this->seed(SupportAdminSeeder::class);
        $password = SupportAdmin::first()->password;

        $this->seed(SupportAdminSeeder::class);

        $this->assertSame(1, SupportAdmin::count());
        $this->assertSame($password, SupportAdmin::first()->password);
    }

    public function test_short_address_leads_to_login_or_to_accounts(): void
    {
        $this->get('/support')->assertRedirect(route('support.login'));

        $this->actingAs(SupportAdmin::create(['name' => 'S', 'email' => 's@tower.test', 'password' => 'support-pass-1']), 'support');

        $this->get('/support')->assertRedirect(route('support.accounts.index'));
    }

    public function test_no_password_is_written_in_the_seeder(): void
    {
        $this->assertDoesNotMatchRegularExpression("/'password'\s*=>\s*'/", file_get_contents(database_path('seeders/SupportAdminSeeder.php')));
    }
}
