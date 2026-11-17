<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Support\SortedTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UnpaidController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = (int) $request->query('tenant_id');
        $tenantName = fn () => DB::table('leases')->join('tenants', 'tenants.id', '=', 'leases.tenant_id')
            ->select('tenants.name')->whereColumn('leases.id', 'invoices.lease_id')->limit(1);
        $unitNumber = DB::table('leases')->join('units', 'units.id', '=', 'leases.unit_id')
            ->select('units.number')->whereColumn('leases.id', 'invoices.lease_id')->limit(1);

        return view('unpaid.index', [
            'tenantId' => $tenantId,
            'tenants' => Tenant::whereHas('leases.invoices', fn ($invoice) => $invoice->unpaid())->orderBy('name')->get(),
            'overdueTotal' => (float) Invoice::overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'upcomingTotal' => (float) Invoice::unpaid()->where('due_date', '>=', today()->toDateString())->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'leases' => $this->byTenant($tenantId),
            'invoices' => SortedTable::query(Invoice::with(['lease.tenant', 'lease.unit.property'])->unpaid()
                ->when($tenantId, fn ($query) => $query->whereIn('lease_id', Lease::where('tenant_id', $tenantId)->select('id')))
                ->orderBy($tenantName())->orderBy('period')->orderBy('id'), 'impayes', [
                    'tenant' => $tenantName(),
                    'unit' => $unitNumber,
                    'period' => 'period',
                    'amount' => 'amount',
                    'paid' => 'paid_amount',
                    'rest' => DB::raw('amount - paid_amount'),
                    'due' => 'due_date',
                ]),
        ]);
    }

    // Une ligne par bail qui a des impayés : ce que chaque locataire doit au total.
    private function byTenant(int $tenantId): SortedTable
    {
        $tenantName = DB::table('tenants')->select('tenants.name')->whereColumn('tenants.id', 'leases.tenant_id')->limit(1);
        $rest = fn () => Invoice::unpaid()->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.lease_id', 'leases.id');

        return SortedTable::query(Lease::with(['tenant', 'unit.property'])
            ->whereHas('invoices', fn ($invoice) => $invoice->unpaid())
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->addSelect([
                'unpaid_total' => $rest(),
                'overdue_total' => Invoice::overdue()->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('invoices.lease_id', 'leases.id'),
                'unpaid_months' => Invoice::unpaid()->selectRaw('COUNT(*)')->whereColumn('invoices.lease_id', 'leases.id'),
            ])
            ->orderBy($tenantName), 'locataires', [
                'tenant' => DB::table('tenants')->select('tenants.name')->whereColumn('tenants.id', 'leases.tenant_id')->limit(1),
                'months' => 'unpaid_months',
                'overdue' => 'overdue_total',
                'unpaid' => 'unpaid_total',
            ]);
    }
}
