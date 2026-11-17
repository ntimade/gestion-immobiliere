<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\RentService;
use App\Support\SortedTable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    private const RELATIONS = ['lease.tenant', 'lease.unit.property'];

    public function index(Request $request): View
    {
        $period = $this->period($request);
        $query = Invoice::with(self::RELATIONS)->where('period', $period);

        return view('invoices.index', [
            'period' => $period,
            'totals' => (clone $query)->selectRaw('COALESCE(SUM(amount), 0) as billed, COALESCE(SUM(paid_amount), 0) as paid')->first(),
            'invoices' => SortedTable::query($query->orderBy('due_date')->orderBy('id'), 'factures', [
                'amount' => 'amount',
                'paid' => 'paid_amount',
                'due' => 'due_date',
            ]),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        return $this->render(collect([$invoice->load(self::RELATIONS)]), __('Facture ').$invoice->number(), route('leases.show', $invoice->lease_id));
    }

    // La facture en fichier, pour la joindre à un message.
    public function pdf(Invoice $invoice): Response
    {
        $invoice->load(self::RELATIONS);

        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml(view('invoices.pdf', $this->data(collect([$invoice]), __('Facture ').$invoice->number(), null) + [
            'organisation' => auth()->user()->organisation,
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::slug(__('Facture').' '.$invoice->number()).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function month(Request $request): View|RedirectResponse
    {
        $period = $this->period($request);

        $invoices = Invoice::with(self::RELATIONS)->where('period', $period)->get()
            ->sortBy(fn (Invoice $invoice) => mb_strtolower($invoice->lease->tenant->name))->values();

        if ($invoices->isEmpty()) {
            return redirect()->route('invoices.index', compact('period'))->with('error', __('Aucune facture à imprimer pour :v1.', ['v1' => period_label($period)]));
        }

        return $this->render($invoices, __('Factures :v1', ['v1' => of_period($period)]), route('invoices.index', compact('period')));
    }

    public function edit(Invoice $invoice): View
    {
        return view('invoices.edit', ['invoice' => $invoice->load(self::RELATIONS)]);
    }

    public function update(Request $request, Invoice $invoice, RentService $rent): RedirectResponse
    {
        $rent->correctInvoice($invoice, $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'due_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => __('montant'), 'due_date' => __('échéance')]));

        return redirect()->route('leases.show', $invoice->lease_id)->with('success', __('Facture corrigée. Les paiements ont été répartis à nouveau.'));
    }

    private function period(Request $request): string
    {
        $period = (string) $request->query('period');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : now()->format('Y-m');
    }

    private function render(Collection $invoices, string $title, string $backUrl): View
    {
        return view('invoices.print', $this->data($invoices, $title, $backUrl) + ['organisation' => auth()->user()->organisation]);
    }

    private function data(Collection $invoices, string $title, ?string $backUrl): array
    {
        // Les autres mois encore dus sur le bail de chaque facture : même total que le message envoyé.
        $otherMonths = $invoices->mapWithKeys(fn (Invoice $invoice) => [$invoice->id => $invoice->unpaidMonths()
            ->reject(fn (Invoice $month) => $month->id === $invoice->id)->values()]);

        return compact('invoices', 'title', 'backUrl', 'otherMonths');
    }
}
