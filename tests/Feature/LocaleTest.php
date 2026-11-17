<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $organisation = Organisation::create([
            'name' => 'Eau Alpha', 'code' => Organisation::generateCode('Eau Alpha'), 'currency' => 'XAF',
        ]);

        $this->actingAs($organisation->users()->create(['name' => 'A', 'email' => 'a@t.test', 'password' => 'secret-pass-1']));
    }

    public function test_french_is_the_default(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('<html lang="fr"', false)->assertSee('Se connecter')
            ->assertSee(route('locale.switch', 'en'), false);
    }

    public function test_switching_stores_the_choice_and_returns_to_the_page(): void
    {
        $this->from(route('login'))->get(route('locale.switch', 'en'))
            ->assertRedirect(route('login'))->assertCookie('locale', 'en', false);

        $this->withUnencryptedCookie('locale', 'en')->get(route('login'))->assertOk()
            ->assertSee('<html lang="en"', false)->assertSee('Sign in')->assertDontSee('Se connecter')
            ->assertSee(route('locale.switch', 'fr'), false);
    }

    public function test_unknown_language_is_refused_and_ignored(): void
    {
        $this->get('/langue/de')->assertNotFound();

        $this->withUnencryptedCookie('locale', 'de')->get(route('login'))->assertOk()->assertSee('Se connecter');
    }

    public function test_application_pages_and_dates_follow_the_language(): void
    {
        $this->signIn();
        $this->withUnencryptedCookie('locale', 'en');

        \Illuminate\Support\Carbon::setTestNow('2026-10-15 10:00:00');
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Dashboard')->assertSee('October 2026')
            ->assertDontSee('Tableau de bord')->assertDontSee('octobre');
        \Illuminate\Support\Carbon::setTestNow();

        Tenant::create(['name' => 'Jean Client']);

        $this->get(route('tenants.index'))->assertOk()->assertSee('Tenants')->assertSee('Up to date')->assertDontSee('À jour');
        $this->get('/page-absente')->assertNotFound()->assertSee('Page not found');
    }

    public function test_messages_and_validation_follow_the_language(): void
    {
        $this->signIn();

        $this->withUnencryptedCookie('locale', 'en')
            ->post(route('tenants.store'), ['name' => 'Jean Client'])
            ->assertSessionHas('success', 'Tenant added.');

        $this->post(route('tenants.store'), [])
            ->assertSessionHasErrors(['name' => 'The name field is required.']);
    }

    public function test_footer_names_the_solution_in_the_chosen_language(): void
    {
        $name = config('app.name');

        $this->get(route('login'))->assertSee('Propulsé par '.$name);
        $this->get('/page-absente')->assertSee('Propulsé par '.$name);

        $this->signIn();
        $this->get(route('tenants.index'))->assertSee('app-footer', false)->assertSee('Propulsé par '.$name);

        $this->withUnencryptedCookie('locale', 'en')->get(route('tenants.index'))
            ->assertSee('Powered by '.$name)->assertDontSee('Propulsé par');
    }

    public function test_account_without_logo_does_not_take_the_logo_of_the_solution(): void
    {
        $this->signIn();

        $sidebar = str($this->get(route('tenants.index'))->getContent())->between('<aside', '</aside>');

        $this->assertStringNotContainsString('images/logo', (string) $sidebar);
        $this->assertStringContainsString('brand-logo', (string) $sidebar);
    }

    public function test_light_mode_is_the_default_and_the_choice_is_kept(): void
    {
        $this->get(route('login'))->assertSee('data-theme="light"', false)->assertSee(route('theme.switch', 'dark'), false);

        $this->from(route('login'))->get(route('theme.switch', 'dark'))
            ->assertRedirect(route('login'))->assertCookie('theme', 'dark', false);

        $this->withUnencryptedCookie('theme', 'dark')->get(route('login'))
            ->assertSee('data-theme="dark"', false)->assertSee(route('theme.switch', 'light'), false);

        $this->get('/theme/rose')->assertNotFound();
        $this->withUnencryptedCookie('theme', '"><script>')->get(route('login'))->assertSee('data-theme="light"', false)->assertDontSee('<script>"', false);
    }

    public function test_printed_documents_stay_outside_the_dark_mode(): void
    {
        $this->assertStringNotContainsString('theme.css', file_get_contents(resource_path('views/invoices/print.blade.php')));
        $this->assertStringNotContainsString('data-theme', file_get_contents(resource_path('views/invoices/print.blade.php')));
    }

    public function test_every_text_has_an_english_translation(): void
    {
        $english = json_decode(file_get_contents(lang_path('en.json')), true);
        $this->assertIsArray($english);

        $missing = [];
        foreach ([resource_path('views'), app_path(), base_path('bootstrap')] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! str_ends_with($file, '.php') || str_contains($file, 'cache')) {
                    continue;
                }

                preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/su", file_get_contents($file), $matches);

                foreach ($matches[1] as $key) {
                    $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $key);

                    if (! isset($english[$key]) && ! str_contains($key, '.sent')) {
                        $missing[] = $key;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));

        foreach (['Patrimoine', 'Loyers', 'Compte'] as $section) {
            $this->assertArrayHasKey($section, $english);
        }

        foreach (config('currencies') as [$label]) {
            $this->assertArrayHasKey($label, $english);
        }

        foreach (config('phone_countries') as [$label]) {
            $this->assertArrayHasKey($label, $english);
        }

        foreach (config('security_questions') as $question) {
            $this->assertArrayHasKey($question, $english);
        }
    }

    public function test_placeholders_are_kept_in_translations(): void
    {
        foreach (json_decode(file_get_contents(lang_path('en.json')), true) as $french => $english) {
            preg_match_all('/:v\d/', $french, $source);
            preg_match_all('/:v\d/', $english, $target);
            sort($source[0]);
            sort($target[0]);

            $this->assertSame($source[0], $target[0], $french);
        }
    }
}
