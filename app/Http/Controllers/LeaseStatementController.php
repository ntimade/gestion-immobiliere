<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Services\LeaseStatement;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LeaseStatementController extends Controller
{
    public function __construct(private LeaseStatement $statement)
    {
    }

    public function show(Request $request, Lease $lease): View
    {
        return view('leases.statement', $this->data($request, $lease) + [
            'backUrl' => route('leases.show', $lease),
            'logoSrc' => route('settings.logo', ['v' => $request->user()->organisation->updated_at?->timestamp]),
        ]);
    }

    public function pdf(Request $request, Lease $lease): Response
    {
        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml(view('leases.statement-pdf', $this->data($request, $lease) + [
            'logoSrc' => $request->user()->organisation->logoDataUri(),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::slug(__('État du bail').' '.$lease->tenant->name.' '.$lease->id).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function data(Request $request, Lease $lease): array
    {
        return $this->statement->build($lease, $this->text($request, 'from'), $this->text($request, 'to')) + [
            'organisation' => $request->user()->organisation,
        ];
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
