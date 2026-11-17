<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Support\PhoneNumber;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(Request $request): View
    {
        $unpaid = Invoice::selectRaw('COALESCE(SUM(invoices.amount - invoices.paid_amount), 0)')
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->whereColumn('leases.tenant_id', 'tenants.id');

        return view('tenants.index', [
            'tenants' => SortedTable::query(Tenant::query()
                ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/[\s().\-+]/', '', $term).'%')))
                ->with(['leases' => fn ($lease) => $lease->active()->with('unit.property')])
                ->addSelect(['unpaid' => $unpaid])
                ->addSelect([
                    'deposit_total' => Lease::selectRaw('COALESCE(SUM(deposit_amount), 0)')->whereColumn('leases.tenant_id', 'tenants.id'),
                    'deposit_refunded' => Lease::selectRaw('COALESCE(SUM(deposit_refunded), 0)')->whereColumn('leases.tenant_id', 'tenants.id'),
                    'deposit_held' => Lease::selectRaw('COALESCE(SUM(deposit_amount - deposit_retained - deposit_refunded), 0)')->whereColumn('leases.tenant_id', 'tenants.id'),
                ])
                ->orderBy('name'), 'locataires', [
                    'name' => 'name',
                    'phone' => 'phone',
                    'unpaid' => 'unpaid',
                ]),
        ]);
    }

    public function create(): View
    {
        return view('tenants.form', ['tenant' => new Tenant]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = Tenant::create(self::validated($request));

        return redirect()->route('tenants.show', $tenant)->with('success', __('Locataire ajouté.'));
    }

    public function show(Tenant $tenant): View
    {
        return view('tenants.show', [
            'tenant' => $tenant,
            'leases' => SortedTable::query(Lease::where('tenant_id', $tenant->id)->with('unit.property')->orderByDesc('start_date'), 'baux', [
                'start' => 'start_date',
                'end' => 'end_date',
                'rent' => 'rent',
            ]),
            'unpaid' => Invoice::whereIn('lease_id', Lease::where('tenant_id', $tenant->id)->select('id'))
                ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'deposits' => Lease::where('tenant_id', $tenant->id)
                ->selectRaw('COALESCE(SUM(deposit_amount), 0) as deposit, COALESCE(SUM(deposit_retained), 0) as retained, COALESCE(SUM(deposit_refunded), 0) as refunded')
                ->toBase()->first(),
        ]);
    }

    public function edit(Tenant $tenant): View
    {
        return view('tenants.form', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update(self::validated($request));

        return redirect()->route('tenants.show', $tenant)->with('success', __('Locataire modifié.'));
    }

    public function destroy(Tenant $tenant): RedirectResponse
    {
        if ($tenant->leases()->exists()) {
            return back()->with('error', __('Ce locataire a un bail enregistré : il ne peut pas être supprimé.'));
        }

        $tenant->delete();

        return redirect()->route('tenants.index')->with('success', __('Locataire supprimé.'));
    }

    // Aussi utilisé à l'entrée dans un logement, quand le locataire est créé en même temps.
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:255'],
            $prefix.'gender' => ['nullable', 'in:M,F'],
            $prefix.'phone' => ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) {
                if (! PhoneNumber::isValid($value)) {
                    $fail(__('Saisissez un seul numéro de téléphone, avec des chiffres uniquement.'));
                }
            }],
            $prefix.'email' => ['nullable', 'email', 'max:255'],
            $prefix.'id_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    private static function validated(Request $request): array
    {
        $data = $request->validate(self::rules());
        $data['phone'] = PhoneNumber::forStorage($data['phone'] ?? null, $request->user()->organisation->phone_country);

        return $data;
    }
}
