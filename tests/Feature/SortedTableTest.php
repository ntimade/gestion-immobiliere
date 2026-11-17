<?php

namespace Tests\Feature;

use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\RentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SortedTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAs($this->account('a@t.test', 'Alpha'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $email, string $name): User
    {
        $organisation = Organisation::create(['name' => $name, 'code' => Organisation::generateCode($name), 'currency' => 'XAF']);

        return $organisation->users()->create(['name' => 'A', 'email' => $email, 'password' => 'secret-pass-1']);
    }

    private function names($table): array
    {
        return collect($table->items())->pluck('name')->all();
    }

    private function lease(): Lease
    {
        $unit = Unit::create(['property_id' => Property::create(['name' => 'Immeuble'])->id, 'number' => '1', 'type' => 'room', 'rent' => 1000]);

        return app(RentService::class)->startLease($unit, Tenant::create(['name' => 'Jean']), ['start_date' => '2026-10-01', 'due_day' => 1, 'rent' => 1000]);
    }

    public function test_tables_show_ten_lines_and_keep_the_rest_on_other_pages(): void
    {
        foreach (range(1, 13) as $index) {
            Tenant::create(['name' => sprintf('Locataire %02d', $index)]);
        }

        $first = $this->get(route('tenants.index'))->assertOk()->assertSee('1 / 2')->original->getData()['tenants'];
        $this->assertCount(13, $first);
        $this->assertCount(10, $first->items());

        $second = $this->get(route('tenants.index', ['locataires_page' => 2]))->assertOk()->original->getData()['tenants'];
        $this->assertSame(['Locataire 11', 'Locataire 12', 'Locataire 13'], $this->names($second));
    }

    public function test_sorting_applies_to_every_line_not_only_the_page(): void
    {
        foreach (range(1, 13) as $index) {
            Tenant::create(['name' => sprintf('Locataire %02d', $index)]);
        }

        $table = $this->get(route('tenants.index', ['locataires_tri' => 'name', 'locataires_ordre' => 'desc']))->assertOk()->original->getData()['tenants'];

        $this->assertSame('Locataire 13', $table->items()[0]->name);
    }

    public function test_every_declared_column_can_be_sorted_in_both_directions(): void
    {
        $lease = $this->lease();
        app(RentService::class)->recordPayment($lease, 500, '2026-11-01', 'Espèces', null);
        $this->get(route('dashboard'));

        foreach ([
            [route('properties.index'), 'biens', ['name', 'kind', 'city', 'apartments', 'rooms', 'units', 'occupied', 'current', 'estimated']],
            [route('properties.show', $lease->unit->property_id), 'logements', ['number', 'type', 'rent']],
            [route('units.index'), 'logements', ['property', 'number', 'type', 'rent']],
            [route('units.show', $lease->unit_id), 'baux', ['start', 'end', 'rent']],
            [route('tenants.index'), 'locataires', ['name', 'phone', 'unpaid']],
            [route('tenants.show', $lease->tenant_id), 'baux', ['start', 'end', 'rent']],
            [route('leases.show', $lease), 'factures', ['period', 'amount', 'paid', 'due']],
            [route('leases.show', $lease), 'paiements', ['date', 'method', 'reference', 'amount']],
            [route('invoices.index'), 'factures', ['amount', 'paid', 'due']],
            [route('unpaid.index'), 'impayes', ['tenant', 'unit', 'period', 'amount', 'paid', 'rest', 'due']],
            [route('unpaid.index'), 'locataires', ['tenant', 'months', 'overdue', 'unpaid']],
            [route('payments.index'), 'paiements', ['date', 'tenant', 'method', 'reference', 'amount']],
            [route('messages.index', ['etat' => 'retards']), 'retards', ['tenant', 'total', 'due']],
        ] as [$url, $table, $columns]) {
            foreach ($columns as $column) {
                foreach (['asc', 'desc'] as $direction) {
                    $separator = str_contains($url, '?') ? '&' : '?';
                    $this->get($url.$separator."{$table}_tri={$column}&{$table}_ordre={$direction}")->assertOk();
                }
            }
        }
    }

    public function test_unknown_sort_is_ignored(): void
    {
        Tenant::create(['name' => 'Bravo']);
        Tenant::create(['name' => 'Alpha']);

        foreach ([
            ['locataires_tri' => 'name; DROP TABLE tenants', 'locataires_ordre' => 'desc'],
            ['locataires_tri' => 'name', 'locataires_ordre' => 'sideways'],
        ] as $query) {
            $table = $this->get(route('tenants.index', $query))->assertOk()->original->getData()['tenants'];
            $this->assertSame(['Alpha', 'Bravo'], $this->names($table));
        }
    }

    public function test_tables_of_a_page_keep_their_own_page_and_sort(): void
    {
        $lease = $this->lease();
        foreach (range(1, 3) as $index) {
            app(RentService::class)->recordPayment($lease, 100 * $index, '2026-11-01', null, null);
        }

        $data = $this->get(route('leases.show', ['lease' => $lease, 'paiements_tri' => 'amount', 'paiements_ordre' => 'desc']))->assertOk()->original->getData();

        $this->assertSame(300.0, (float) $data['payments']->items()[0]->amount);
        $this->assertSame(1, $data['invoices']->currentPage());
    }

    public function test_sort_links_show_the_active_column(): void
    {
        Tenant::create(['name' => 'Alpha']);

        $this->get(route('tenants.index'))->assertSee('locataires_tri=name&amp;locataires_ordre=asc', false)->assertDontSee('aria-sort="ascending"', false);

        $this->get(route('tenants.index', ['locataires_tri' => 'name', 'locataires_ordre' => 'asc']))
            ->assertSee('aria-sort="ascending"', false)->assertSee('href="'.e(route('tenants.index', ['locataires_tri' => 'name', 'locataires_ordre' => 'desc'])).'#t-locataires"', false);
    }
}
