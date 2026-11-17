<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Services\LeaseStatement;
use App\Support\SortedTable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

// Tout l'historique d'un locataire : chaque bail, terminé ou non, avec ses mois, ses versements et sa caution.
class TenantHistoryController extends Controller
{
    public function __construct(private LeaseStatement $statement)
    {
    }

    // La page du menu : tous les locataires, partis ou non, pour générer leur historique.
    public function index(Request $request): View
    {
        $invoices = fn (string $sum) => Invoice::selectRaw("COALESCE(SUM({$sum}), 0)")
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->whereColumn('leases.tenant_id', 'tenants.id');
        $leases = fn (string $sum) => Lease::selectRaw("COALESCE(SUM({$sum}), 0)")->whereColumn('leases.tenant_id', 'tenants.id');

        return view('tenants.history-index', [
            'tenants' => SortedTable::query(Tenant::query()
                ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/[\s().\-+]/', '', $term).'%')))
                ->withCount('leases')
                ->addSelect([
                    'paid_total' => $invoices('invoices.paid_amount'),
                    'unpaid_total' => $invoices('invoices.amount - invoices.paid_amount'),
                    'deposit_total' => $leases('deposit_amount'),
                    'deposit_retained' => $leases('deposit_retained'),
                    'deposit_refunded' => $leases('deposit_refunded'),
                ])
                ->orderBy('name'), 'historiques', [
                    'name' => 'name',
                    'paid' => 'paid_total',
                    'unpaid' => 'unpaid_total',
                ]),
        ]);
    }

    public function show(Request $request, Tenant $tenant): View
    {
        return view('tenants.history', $this->data($request, $tenant) + [
            'logoSrc' => route('settings.logo', ['v' => $request->user()->organisation->updated_at?->timestamp]),
        ]);
    }

    public function pdf(Request $request, Tenant $tenant): Response
    {
        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml(view('tenants.history-pdf', $this->data($request, $tenant) + [
            'logoSrc' => $request->user()->organisation->logoDataUri(),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::slug(__('Historique du locataire').' '.$tenant->name).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function data(Request $request, Tenant $tenant): array
    {
        $entries = Lease::where('tenant_id', $tenant->id)->orderBy('start_date')->orderBy('id')->get()
            ->map(fn (Lease $lease) => $this->statement->build($lease));

        $sum = fn (callable $value) => round((float) $entries->sum($value), 3);

        return [
            'tenant' => $tenant,
            'organisation' => $request->user()->organisation,
            'entries' => $entries,
            'totals' => [
                'billed' => $sum(fn ($entry) => $entry['billed']),
                'paid' => $sum(fn ($entry) => $entry['paid']),
                'unpaid' => $sum(fn ($entry) => $entry['unpaid']),
                'deposit' => $sum(fn ($entry) => (float) $entry['lease']->deposit_amount),
                'retained' => $sum(fn ($entry) => (float) $entry['lease']->deposit_retained),
                'refunded' => $sum(fn ($entry) => (float) $entry['lease']->deposit_refunded),
                'held' => $sum(fn ($entry) => $entry['lease']->depositHeld()),
            ],
        ];
    }
}
