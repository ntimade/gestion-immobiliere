<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Services\LegalNotice;
use App\Support\InvoiceMessage;
use App\Support\PhoneNumber;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LegalNoticeController extends Controller
{
    public function __construct(private LegalNotice $notices)
    {
    }

    // On note l'ouverture de l'application : on ne peut pas savoir si le message est parti.
    public function open(Lease $lease, string $channel): Response
    {
        $notice = $this->notices->forLease($lease);

        if (! $notice) {
            return back()->with('error', __('Ce locataire n\'est plus concerné par la procédure.'));
        }

        if (! $notice->reachable) {
            return back()->with('error', __('Le numéro de ce locataire est absent ou à corriger.'));
        }

        $lease->forceFill(['legal_notice_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::legalNoticeLink($notice->lease, $notice, $channel));
    }

    // Le décompte de sortie : ce qui est rendu sur la caution, ce qui est retenu et pourquoi.
    public function openExit(Lease $lease, string $channel): Response
    {
        $lease->load(['tenant', 'unit.property']);

        if (! $lease->end_date) {
            return back()->with('error', __('La sortie de ce locataire n\'est pas enregistrée.'));
        }

        if (! PhoneNumber::isInternational($lease->tenant->phone)) {
            return back()->with('error', __('Le numéro de ce locataire est absent ou à corriger.'));
        }

        $lease->forceFill(['exit_notice_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::exitNoticeLink($lease, $channel));
    }

    public function openNotice(Lease $lease, string $channel): Response
    {
        return $this->openLeaseMessage($lease, $channel, 'notice_opened_at', fn () => $lease->onNotice()
            ? null : __('Enregistrez d\'abord le préavis du locataire.'), fn () => InvoiceMessage::noticeLink($lease, $channel));
    }

    public function openInspection(Lease $lease, string $channel): Response
    {
        return $this->openLeaseMessage($lease, $channel, 'inspection_opened_at', fn () => $lease->onNotice() && $lease->inspection_at
            ? null : __('Fixez d\'abord la date de l\'état des lieux.'), fn () => InvoiceMessage::inspectionLink($lease, $channel));
    }

    private function openLeaseMessage(Lease $lease, string $channel, string $column, callable $refusal, callable $link): Response
    {
        $lease->load(['tenant', 'unit.property']);

        if ($error = $refusal()) {
            return back()->with('error', $error);
        }

        if (! PhoneNumber::isInternational($lease->tenant->phone)) {
            return back()->with('error', __('Le numéro de ce locataire est absent ou à corriger.'));
        }

        $lease->forceFill([$column => now()])->save();

        return InvoiceMessage::open($link());
    }

    public function letter(Request $request, Lease $lease): Response
    {
        $notice = $this->notices->forLease($lease);

        if (! $notice) {
            return back()->with('error', __('Ce locataire n\'est plus concerné par la procédure.'));
        }

        $organisation = $request->user()->organisation;

        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml(view('leases.legal-notice-pdf', [
            'notice' => $notice,
            'lease' => $notice->lease,
            'lastPayment' => $lease->payments()->latest('paid_at')->latest('id')->first(),
            'organisation' => $organisation,
            'logoSrc' => $organisation->logoDataUri(),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::slug(__('Notification de procédure').' '.$notice->lease->tenant->name.' '.$lease->id).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
