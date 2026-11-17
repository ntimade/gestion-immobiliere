<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Organisation;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $organisation = Organisation::create(['name' => 'Eau Alpha', 'code' => Organisation::generateCode('Eau Alpha'), 'currency' => 'XAF']);
        $organisation->forceFill(['phone_country' => 'CM', 'trial_ends_at' => now()->addDays(10)])->save();
        $this->user = $organisation->users()->create(['name' => 'Propriétaire', 'email' => 'a@test.test', 'password' => 'secret-pass-123']);
        $this->actingAs($this->user);
    }

    public static function numbers(): array
    {
        return [
            'national' => ['699000000', 'CM', '+237699000000'],
            'espaces' => ['699 00 00 00', 'CM', '+237699000000'],
            'séparateurs' => ['699-00.00(00)', 'CM', '+237699000000'],
            'déjà complet' => ['+237 699 00 00 00', 'CM', '+237699000000'],
            'indicatif sans plus' => ['237699000000', 'CM', '+237699000000'],
            'ancien numéro sans plus' => ['23774672682', 'CM', '+23774672682'],
            'ancien numéro avec plus' => ['+237 74672682', 'CM', '+23774672682'],
            'sans plus et pays inconnu' => ['237 74672682', null, '+23774672682'],
            'autre pays sans plus' => ['33612345678', 'CM', '+33612345678'],
            'préfixe 00' => ['00237699000000', 'CM', '+237699000000'],
            'national commençant comme l\'indicatif' => ['237000000', 'CM', '+237237000000'],
            'autre pays conservé' => ['+33 6 12 34 56 78', 'CM', '+33612345678'],
            'zéro de tête retiré' => ['06 12 34 56 78', 'FR', '+33612345678'],
            'zéro de tête conservé' => ['07 12 34 56 78', 'CI', '+2250712345678'],
            'illisible' => ['appeler le gardien', 'CM', null],
            'deux numéros' => ['699000000 / 677000000', 'CM', null],
            'trop court' => ['12345', 'CM', null],
            'trop long' => ['1234567890123456', 'CM', null],
            'pays inconnu' => ['699000000', null, null],
            'pays inconnu mais numéro complet' => ['+237699000000', null, '+237699000000'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numbers_are_put_in_international_format(string $raw, ?string $country, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($raw, $country));
    }

    public function test_tenant_form_saves_the_international_format(): void
    {
        $this->post(route('tenants.store'), ['name' => 'Jean', 'phone' => '699 00 00 00'])->assertSessionHasNoErrors();
        $tenant = Tenant::where('name', 'Jean')->firstOrFail();
        $this->assertSame('+237699000000', $tenant->phone);

        $this->put(route('tenants.update', $tenant), ['name' => 'Jean', 'phone' => '677 00 00 00'])->assertSessionHasNoErrors();
        $this->assertSame('+237677000000', $tenant->fresh()->phone);

        $this->put(route('tenants.update', $tenant), ['name' => 'Jean', 'phone' => ''])->assertSessionHasNoErrors();
        $this->assertNull($tenant->fresh()->phone);
    }

    public function test_tenant_form_refuses_an_unreadable_number(): void
    {
        $this->post(route('tenants.store'), ['name' => 'Jean', 'phone' => 'appeler le gardien'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Tenant::count());
    }

    public function test_number_stays_as_typed_until_the_country_is_chosen(): void
    {
        $this->user->organisation->forceFill(['phone_country' => null])->save();

        $this->post(route('tenants.store'), ['name' => 'Jean', 'phone' => '699 00 00 00'])->assertSessionHasNoErrors();

        $this->assertSame('699 00 00 00', Tenant::first()->phone);
    }

    public function test_choosing_the_country_converts_existing_numbers_of_this_account_only(): void
    {
        $organisation = $this->user->organisation;
        $organisation->forceFill(['phone_country' => null])->save();
        $good = Tenant::create(['name' => 'A', 'phone' => '699 00 00 00']);
        $bad = Tenant::create(['name' => 'B', 'phone' => 'appeler le gardien']);

        $other = Organisation::create(['name' => 'Eau Beta', 'code' => Organisation::generateCode('Eau Beta'), 'currency' => 'XAF']);
        $foreign = new Tenant(['name' => 'C', 'phone' => '677 00 00 00']);
        $foreign->organisation_id = $other->id;
        $foreign->save();

        $this->from(route('settings.edit'))->put(route('settings.update'), [
            'name' => 'Eau Alpha', 'phone_country' => 'CM', 'currency' => 'XAF', 'reminder_days' => 3,
        ])->assertSessionHasNoErrors()->assertSessionHas('error');

        $this->assertSame('CM', $organisation->fresh()->phone_country);
        $this->assertSame('+237699000000', $good->fresh()->phone);
        $this->assertSame('appeler le gardien', $bad->fresh()->phone);
        $this->assertSame('677 00 00 00', Tenant::withoutGlobalScopes()->find($foreign->id)->phone);

        $this->get(route('tenants.index'))->assertSee(__('À corriger'));
    }

    public function test_unknown_country_is_refused(): void
    {
        $this->put(route('settings.update'), ['name' => 'Eau Alpha', 'phone_country' => 'ZZ', 'currency' => 'XAF', 'reminder_days' => 3])
            ->assertSessionHasErrors('phone_country');
    }

    public function test_search_finds_a_number_typed_with_spaces(): void
    {
        Tenant::create(['name' => 'Jean', 'phone' => '+237699000000']);

        $this->get(route('tenants.index', ['q' => '699 00']))->assertSee('Jean');
    }

    public function test_search_ignores_the_plus_sign(): void
    {
        Tenant::create(['name' => 'Jean', 'phone' => '+237699000000']);

        $this->get(route('tenants.index', ['q' => '237699000000']))->assertSee('Jean');
        $this->get(route('tenants.index', ['q' => '+237699000000']))->assertSee('Jean');
    }

    public function test_country_is_guessed_from_a_complete_number(): void
    {
        $this->assertSame('CM', PhoneNumber::guessCountry('+237 699 00 00 00'));
        $this->assertSame('FR', PhoneNumber::guessCountry('+33612345678'));
        $this->assertNull(PhoneNumber::guessCountry('699000000'));
    }
}
