<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\RentService;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private RentService $rent)
    {
    }

    public function index(Request $request): View
    {
        $query = Payment::query()
            ->when($request->query('tenant_id'), fn ($q, $id) => $q->whereIn('lease_id', Lease::where('tenant_id', $id)->select('id')))
            ->when($request->query('from'), fn ($q, $date) => $q->whereDate('paid_at', '>=', $date))
            ->when($request->query('to'), fn ($q, $date) => $q->whereDate('paid_at', '<=', $date));

        return view('payments.index', [
            'total' => (clone $query)->sum('amount'),
            'payments' => SortedTable::query($query->with(['lease.tenant', 'lease.unit.property', 'allocations.invoice'])->latest('paid_at')->latest('id'), 'paiements', [
                'date' => 'paid_at',
                'tenant' => DB::table('leases')->join('tenants', 'tenants.id', '=', 'leases.tenant_id')
                    ->select('tenants.name')->whereColumn('leases.id', 'payments.lease_id')->limit(1),
                'method' => 'method',
                'reference' => 'reference',
                'amount' => 'amount',
            ]),
            'tenants' => Tenant::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Lease $lease): RedirectResponse
    {
        $data = $this->validated($request);

        $payment = $this->rent->recordPayment($lease, (float) $data['amount'], $data['paid_at'], $data['method'] ?? null, $data['reference'] ?? null);

        return redirect()->route('leases.show', $lease)
            ->with('success', __('Paiement enregistré. Mois réglés : :v1.', ['v1' => $payment->coveredLabel()])
                .($lease->paidThrough() ? ' '.__('Loyer payé jusqu\'au :v1', ['v1' => $lease->paidThrough()->format('d/m/Y')]).'.' : ''))
            ->with('notify_payment', $payment->id);
    }

    public function edit(Payment $payment): View
    {
        return view('payments.edit', ['payment' => $payment->load('lease.tenant', 'lease.unit.property', 'allocations.invoice')]);
    }

    public function update(Request $request, Payment $payment): RedirectResponse
    {
        $data = $this->validated($request);

        $this->rent->updatePayment($payment, (float) $data['amount'], $data['paid_at'], $data['method'] ?? null, $data['reference'] ?? null);

        return redirect()->route('leases.show', $payment->lease_id)->with('success', __('Paiement corrigé. Les mois réglés ont été recalculés.'));
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->rent->cancelPayment($payment);

        return back()->with('success', __('Paiement annulé. Les mois réglés ont été recalculés.'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [], ['amount' => __('montant'), 'paid_at' => __('date du paiement')]);
    }
}
