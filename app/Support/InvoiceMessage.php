<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Stay;
use App\Services\LegalNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Messages préparés dans l'application de l'utilisateur : c'est lui qui appuie sur Envoyer.
// Dans WhatsApp, *texte* s'affiche en gras. Pas d'émoji : ils arrivent cassés à l'ouverture du message. Les SMS restent courts et sans accents :
// un accent peut réduire un SMS à 70 caractères et doubler son coût.
class InvoiceMessage
{
    private const RULE = '━━━━━━━━━━━━━━━━━━';

    public static function whatsapp(Invoice $invoice): string
    {
        $lease = $invoice->lease;
        $months = $invoice->unpaidMonths();

        return self::card(
            __('Facture de loyer — :v1', ['v1' => $invoice->cycleLabel()]),
            [self::greeting($lease)],
            [
                __('N° de facture : :v1', ['v1' => $invoice->number()]),
                __('Logement : :v1', ['v1' => $lease->unit->label()]),
                __('Loyer mensuel : :v1', ['v1' => money($invoice->amount)]),
                ...self::billingLine($lease),
                $lease->depositSummary(),
            ],
            self::totalLines($months, $invoice->isOverdue()
                ? __('Échéance dépassée depuis le *:v1*. Merci de régler dès que possible.', ['v1' => $invoice->due_date->format('d/m/Y')])
                : __('À régler avant le *:v1*.', ['v1' => $invoice->due_date->format('d/m/Y')])),
            __('Merci de votre confiance.'),
            self::monthLines($months),
        );
    }

    public static function sms(Invoice $invoice): string
    {
        $lease = $invoice->lease;
        $total = $invoice->balance() + self::arrears($invoice);

        $text = self::prefix().__('Bonjour :v1, loyer :v2, logement :v3.', [
            'v1' => $lease->tenant->smsName(),
            'v2' => $invoice->cycleLabel(),
            'v3' => $lease->unit->number,
        ]);

        $text .= $total > 0
            ? ' '.__('Total a payer : :v1 avant le :v2.', ['v1' => money($total), 'v2' => $invoice->due_date->format('d/m/Y')])
            : ' '.__('Ce loyer est regle. Merci.');

        return self::finishSms($text);
    }

    // Le rappel envoyé quelques jours avant l'échéance.
    public static function reminder(Invoice $invoice): string
    {
        $lease = $invoice->lease;
        $months = $invoice->unpaidMonths();

        return self::card(
            __('Rappel de loyer'),
            [self::greeting($lease), self::dueSentence($invoice)],
            [__('Logement : :v1', ['v1' => $lease->unit->label()]), ...self::billingLine($lease), $lease->depositSummary()],
            self::totalLines($months),
            __('Si vous avez déjà payé, ne tenez pas compte de ce message.'),
            self::monthLines($months),
        );
    }

    public static function reminderSms(Invoice $invoice): string
    {
        $text = self::prefix().__('Rappel pour :v1, logement :v2 : :v3 a payer avant le :v4.', [
            'v1' => $invoice->lease->tenant->smsName(),
            'v2' => $invoice->lease->unit->number,
            'v3' => money($invoice->balance() + self::arrears($invoice)),
            'v4' => $invoice->due_date->format('d/m/Y'),
        ]);

        return self::finishSms($text);
    }

    // La confirmation après un paiement, avec les mois qu'il règle.
    public static function payment(Payment $payment): string
    {
        $lease = $payment->lease;
        $remaining = $lease->unpaidTotal();

        $details = [__('Date : :v1', ['v1' => $payment->paid_at->format('d/m/Y')])];

        if ($payment->method) {
            $details[] = __('Mode : :v1', ['v1' => $payment->method]);
        }

        if ($payment->reference) {
            $details[] = __('Référence : :v1', ['v1' => $payment->reference]);
        }

        $details[] = __('Logement : :v1', ['v1' => $lease->unit->label()]);
        $details[] = __('Mois réglés : :v1', ['v1' => $payment->coveredLabel()]);
        if ($paidThrough = $lease->paidThrough()) {
            $details[] = __('Loyer payé jusqu\'au :v1', ['v1' => '*'.$paidThrough->format('d/m/Y').'*']);
        }

        $details[] = $lease->depositSummary();

        return self::card(
            __('Confirmation de paiement'),
            [self::greeting($lease), __('Nous confirmons avoir reçu votre paiement de *:v1*.', ['v1' => money($payment->amount)])],
            $details,
            [$remaining > 0
                ? '*'.__('Reste à payer : :v1', ['v1' => money($remaining)]).'*'
                : '*'.__('Votre loyer est à jour.').'*'],
            __('Merci de votre confiance.'),
        );
    }

    public static function paymentSms(Payment $payment): string
    {
        $lease = $payment->lease;
        $remaining = $lease->unpaidTotal();

        // Tenir dans un seul SMS : la date « payé jusqu'au » dit déjà quels mois sont réglés.
        $paidThrough = $lease->paidThrough();
        $text = self::prefix().__('Recu :v1 de :v2 le :v3, logement :v4.', [
            'v1' => money($payment->amount),
            'v2' => $lease->tenant->smsName(),
            'v3' => $payment->paid_at->format('d/m/Y'),
            'v4' => $lease->unit->number,
        ]);
        $text .= ' '.($paidThrough
            ? __('Paye jusqu\'au :v1.', ['v1' => $paidThrough->format('d/m/Y')])
            : __('Mois : :v1.', ['v1' => self::coveredRange($payment)]));
        $text .= ' '.($remaining > 0 ? __('Reste a payer : :v1.', ['v1' => money($remaining)]) : __('Loyer a jour.'));

        return self::finishSms($text);
    }

    // La relance d'un locataire en retard : tous ses mois échus sur ce bail.
    public static function lateNotice(Lease $lease, Collection $invoices): string
    {
        return self::card(
            __('Loyer en retard'),
            [self::greeting($lease), __('L\'échéance de paiement est dépassée pour le logement :v1 :', ['v1' => $lease->unit->label()])],
            [...$invoices->map(fn (Invoice $invoice) => $invoice->label().' : '.money($invoice->balance()))->all(), $lease->depositSummary()],
            ['*'.__('Total à payer : :v1', ['v1' => money($invoices->sum(fn (Invoice $invoice) => $invoice->balance()))]).'*', __('Merci de régler dès que possible.')],
            __('Si vous avez déjà payé, ne tenez pas compte de ce message.'),
        );
    }

    public static function lateNoticeSms(Lease $lease, Collection $invoices): string
    {
        $text = self::prefix().__('Loyer en retard, :v1, logement :v2 : :v3 a payer. Merci de regler rapidement.', [
            'v1' => $lease->tenant->smsName(),
            'v2' => $lease->unit->number,
            'v3' => money($invoices->sum(fn (Invoice $invoice) => $invoice->balance())),
        ]);

        return self::finishSms($text);
    }

    // La notification du début de la procédure judiciaire : le détail complet est dans la lettre jointe.
    public static function legalNotice(Lease $lease, object $notice): string
    {
        $details = [
            __('Réf. : :v1', ['v1' => $notice->reference]),
            __('Logement : :v1', ['v1' => $lease->unit->label()]),
            __('Échéance impayée la plus ancienne : :v1', ['v1' => $notice->invoices->first()->due_date->format('d/m/Y')]),
        ];

        if ($notice->history->isNotEmpty()) {
            $details[] = __('Messages précédents : :v1, le dernier le :v2', ['v1' => $notice->history->count(), 'v2' => $notice->history->last()->date->format('d/m/Y')]);
        }

        $details[] = $lease->depositSummary();

        return self::card(
            __('Notification de procédure judiciaire'),
            [
                trim($lease->tenant->title().' *'.$lease->tenant->name.'*').',',
                '',
                $notice->history->isNotEmpty()
                    ? __('Malgré nos précédents messages, des loyers restent impayés après leur échéance.')
                    : __('Des loyers restent impayés après leur échéance.'),
                '',
                '*'.LegalNotice::procedureSentence($lease).'*',
            ],
            $details,
            ['*'.__('Total dû : :v1', ['v1' => money($notice->total)]).'*', __('La lettre de notification est jointe à ce message.')],
            __('Tout règlement reçu sera déduit des sommes dues. Pour régler ou pour toute question, contactez-nous.'),
            $notice->invoices->map(fn (Invoice $month) => '• '.$month->label().' : '.money($month->balance()))->values()->all(),
        );
    }

    // Tenir dans un seul SMS : le détail des mois reste dans la lettre et sur WhatsApp.
    public static function legalNoticeSms(Lease $lease, object $notice): string
    {
        $text = self::prefix().__('Loyers impayes, :v1, logement :v2 : :v3 depuis le :v4. Procedure judiciaire engagee.', [
            'v1' => $lease->tenant->smsName(),
            'v2' => $lease->unit->number,
            'v3' => money($notice->total),
            'v4' => $notice->invoices->first()->due_date->format('d/m/Y'),
        ]);

        return self::finishSms($text);
    }

    public static function legalNoticeLink(Lease $lease, object $notice, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($lease->tenant->phone, self::legalNoticeSms($lease, $notice))
            : self::whatsappUrl($lease->tenant->phone, self::legalNotice($lease, $notice));
    }

    // La confirmation du préavis : date de départ, ce qui reste à payer d'ici là, et le sort de la caution.
    public static function noticeConfirmation(Lease $lease): string
    {
        $due = $lease->dueUntilDeparture();
        $details = [
            __('Logement : :v1', ['v1' => $lease->unit->label()]),
            __('Date de départ : :v1', ['v1' => '*'.$lease->notice_end_date->format('d/m/Y').'*']),
            __('Dernier mois facturé : :v1', ['v1' => period_label($lease->notice_end_date->format('Y-m'))]),
        ];

        if ($paidThrough = $lease->paidThrough()) {
            $details[] = __('Loyer payé jusqu\'au :v1', ['v1' => $paidThrough->format('d/m/Y')]);
        }

        $details[] = $lease->depositSummary();

        $highlight = [$due > 0
            ? '*'.__('Reste à payer avant le départ : :v1', ['v1' => money($due)]).'*'
            : '*'.__('Votre loyer est réglé jusqu\'au départ.').'*'];
        $highlight[] = $lease->inspection_at
            ? __('État des lieux de sortie : :v1', ['v1' => self::visitDate($lease)])
            : __('La date de l\'état des lieux de sortie vous sera communiquée.');

        return self::card(
            __('Confirmation de préavis'),
            [self::greeting($lease), __('Nous avons bien reçu votre préavis de départ le :v1.', ['v1' => $lease->notice_given_on->format('d/m/Y')])],
            $details,
            $highlight,
            __('La caution vous sera remboursée après l\'état des lieux de sortie, déduction faite des éventuelles réparations.'),
        );
    }

    public static function noticeConfirmationSms(Lease $lease): string
    {
        $due = $lease->dueUntilDeparture();
        $text = self::prefix().__('Preavis recu, :v1, logement :v2 : depart le :v3.', [
            'v1' => $lease->tenant->smsName(),
            'v2' => $lease->unit->number,
            'v3' => $lease->notice_end_date->format('d/m/Y'),
        ]);
        $text .= ' '.($due > 0 ? __('Reste a payer : :v1.', ['v1' => money($due)]) : __('Loyer regle jusqu\'au depart.'));
        $text .= ' '.__('Caution rendue apres l\'etat des lieux.');

        return self::finishSms($text);
    }

    // L'invitation à l'état des lieux de sortie, pendant le préavis.
    public static function inspectionInvitation(Lease $lease): string
    {
        $details = [
            __('Logement : :v1', ['v1' => $lease->unit->label()]),
            __('Date : :v1', ['v1' => '*'.self::visitDate($lease).'*']),
        ];

        if ($lease->unit->property->address) {
            $details[] = __('Adresse : :v1', ['v1' => $lease->unit->property->address]);
        }

        $details[] = __('Départ prévu : :v1', ['v1' => $lease->notice_end_date->format('d/m/Y')]);

        return self::card(
            __('État des lieux de sortie'),
            [self::greeting($lease), __('Dans le cadre de votre départ, nous vous invitons à l\'état des lieux de sortie de votre logement.')],
            $details,
            ['*'.__('Merci d\'être présent et de prévoir les clés.').'*', $lease->depositSummary()],
            __('Les réparations éventuellement constatées seront déduites de la caution, avec leur justification.'),
        );
    }

    public static function inspectionInvitationSms(Lease $lease): string
    {
        $text = self::prefix().__('Etat des lieux de sortie, :v1, logement :v2 : :v3. Merci d\'etre present avec les cles.', [
            'v1' => $lease->tenant->smsName(),
            'v2' => $lease->unit->number,
            'v3' => $lease->inspection_at->format('d/m/Y \à H\hi'),
        ]);

        return self::finishSms($text);
    }

    public static function noticeLink(Lease $lease, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($lease->tenant->phone, self::noticeConfirmationSms($lease))
            : self::whatsappUrl($lease->tenant->phone, self::noticeConfirmation($lease));
    }

    public static function inspectionLink(Lease $lease, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($lease->tenant->phone, self::inspectionInvitationSms($lease))
            : self::whatsappUrl($lease->tenant->phone, self::inspectionInvitation($lease));
    }

    // « jeudi 3 décembre 2026 à 10h00 »
    private static function visitDate(Lease $lease): string
    {
        return __(':v1 à :v2', [
            'v1' => $lease->inspection_at->copy()->locale(app()->getLocale())->isoFormat('dddd D MMMM YYYY'),
            'v2' => $lease->inspection_at->format('H\hi'),
        ]);
    }

    // Le décompte remis à la sortie : ce qui est rendu sur la caution, ce qui est retenu et pourquoi.
    public static function exitNotice(Lease $lease): string
    {
        $details = [
            __('Logement : :v1', ['v1' => $lease->unit->label()]),
            __('Date de sortie : :v1', ['v1' => $lease->end_date->format('d/m/Y')]),
        ];

        $highlight = [];

        if ((float) $lease->deposit_amount > 0) {
            $details[] = __('Caution versée : :v1', ['v1' => money($lease->deposit_amount)]);
            $refundedOn = $lease->depositRefundedOn();

            if ($refundedOn) {
                $highlight[] = '*'.__('Caution remboursée : :v1, le :v2', ['v1' => money($lease->deposit_refunded), 'v2' => $refundedOn->format('d/m/Y')]).'*';
            } else {
                $highlight[] = '*'.__('Caution remboursée : :v1', ['v1' => money(0)]).'*';
            }

            if ((float) $lease->deposit_retained > 0) {
                $highlight[] = __('Retenue : :v1', ['v1' => money($lease->deposit_retained)]);
                $highlight[] = __('Justification : :v1', ['v1' => $lease->deposit_note]);
                if (($toRent = $lease->depositToRent()) > 0) {
                    $highlight[] = __('Dont affectés aux loyers impayés : :v1', ['v1' => money($toRent)]);
                }
            }
        } else {
            $highlight[] = '*'.__('Aucune caution enregistrée.').'*';
        }

        foreach ($lease->rentRefunds as $refund) {
            $highlight[] = '*'.__('Loyers remboursés : :v1, le :v2', ['v1' => money($refund->amount), 'v2' => $refund->refunded_on->format('d/m/Y')]).'*';
            $highlight[] = $refund->note;
        }

        $unpaid = $lease->unpaidTotal();
        if ($unpaid > 0) {
            $highlight[] = __('Loyers encore dus : :v1', ['v1' => money($unpaid)]);
        }

        return self::card(
            __('Décompte de sortie'),
            [self::greeting($lease), __('Nous confirmons la fin de votre location.')],
            $details,
            $highlight,
            __('Merci de votre confiance.'),
        );
    }

    public static function exitNoticeSms(Lease $lease): string
    {
        $text = self::prefix().__('Sortie :v1, logement :v2, :v3.', [
            'v1' => $lease->tenant->smsName(),
            'v2' => $lease->unit->number,
            'v3' => $lease->end_date->format('d/m/Y'),
        ]);

        if (($rentRefund = (float) $lease->rentRefunds->sum('amount')) > 0) {
            $text .= ' '.__('Loyers rendus : :v1.', ['v1' => money($rentRefund)]);
        }

        if ((float) $lease->deposit_amount <= 0) {
            return self::finishSms($text);
        }

        if ((float) $lease->deposit_retained <= 0) {
            return self::finishSms($text.' '.__('Caution rendue : :v1.', ['v1' => money($lease->deposit_refunded)]));
        }

        // Le motif prend la place qui reste dans un seul SMS ; le texte complet part sur WhatsApp.
        $deposit = ' '.__('Caution : :v1 rendus, :v2 retenus', ['v1' => money($lease->deposit_refunded), 'v2' => money($lease->deposit_retained)]);
        $room = 160 - strlen(self::finishSms($text.$deposit.' ().'));
        $reason = Str::ascii((string) $lease->deposit_note);

        return self::finishSms($text.$deposit.($room >= 8 ? ' ('.Str::limit($reason, $room - 3).').' : '.'));
    }

    public static function exitNoticeLink(Lease $lease, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($lease->tenant->phone, self::exitNoticeSms($lease))
            : self::whatsappUrl($lease->tenant->phone, self::exitNotice($lease));
    }

    /**
     * Le remerciement envoyé après l'enregistrement d'un séjour à la nuit. Il reprend tout le détail de la
     * facture et l'heure à laquelle le logement doit être libéré : le client le garde comme facture.
     */
    public static function stayThanks(Stay $stay): string
    {
        $guest = $stay->guest;
        $details = [
            __('N° de facture : :v1', ['v1' => $stay->number()]),
            __('Logement : :v1', ['v1' => $stay->unit->label()]),
            __('Arrivée : le :v1 à :v2', ['v1' => $stay->check_in_at->format('d/m/Y'), 'v2' => $stay->check_in_at->format('H:i')]),
            __('Durée : :v1 (:v2 h)', ['v1' => $stay->daysLabel(), 'v2' => 24 * $stay->days]),
            __('Tarif journalier : :v1', ['v1' => money($stay->daily_rate)]),
            __('Montant du séjour : :v1', ['v1' => money($stay->amount)]),
            __('Payé : :v1', ['v1' => money($stay->paid_amount)]),
        ];

        if ($stay->method) {
            $details[] = __('Mode : :v1', ['v1' => $stay->method]);
        }

        return self::card(
            __('Merci pour votre séjour'),
            [
                __('Bonjour :v1 *:v2*,', ['v1' => $guest->title(), 'v2' => $guest->name]),
                __('Merci d\'avoir choisi :v1. Votre séjour est enregistré.', ['v1' => auth()->user()->organisation->name]),
            ],
            $details,
            [
                '*'.__('Libération du logement : le :v1 à :v2', ['v1' => $stay->check_out_at->format('d/m/Y'), 'v2' => $stay->check_out_at->format('H:i')]).'*',
                $stay->balance() > 0 ? '*'.__('Reste à payer : :v1', ['v1' => money($stay->balance())]).'*' : __('Séjour entièrement payé.'),
            ],
            __('Gardez ce message : il vaut facture. Nous vous souhaitons un agréable séjour.'),
        );
    }

    public static function stayThanksSms(Stay $stay): string
    {
        $text = self::prefix().__('Merci :v1. Sejour :v2, logement :v3, :v4. Liberation le :v5 a :v6.', [
            'v1' => $stay->guest->smsName(),
            'v2' => $stay->number(),
            'v3' => $stay->unit->number,
            'v4' => money($stay->paid_amount).' '.__('paye'),
            'v5' => $stay->check_out_at->format('d/m/Y'),
            'v6' => $stay->check_out_at->format('H:i'),
        ]);

        if ($stay->balance() > 0) {
            $text .= ' '.__('Reste a payer : :v1.', ['v1' => money($stay->balance())]);
        }

        return self::finishSms($text);
    }

    public static function stayLink(Stay $stay, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($stay->guest->phone, self::stayThanksSms($stay))
            : self::whatsappUrl($stay->guest->phone, self::stayThanks($stay));
    }

    public static function whatsappLink(Invoice $invoice): string
    {
        return self::whatsappUrl($invoice->lease->tenant->phone, self::whatsapp($invoice));
    }

    public static function smsLink(Invoice $invoice): string
    {
        return self::smsUrl($invoice->lease->tenant->phone, self::sms($invoice));
    }

    public static function reminderLink(Invoice $invoice, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($invoice->lease->tenant->phone, self::reminderSms($invoice))
            : self::whatsappUrl($invoice->lease->tenant->phone, self::reminder($invoice));
    }

    public static function paymentLink(Payment $payment, string $channel): string
    {
        $phone = $payment->lease->tenant->phone;

        return $channel === 'sms'
            ? self::smsUrl($phone, self::paymentSms($payment))
            : self::whatsappUrl($phone, self::payment($payment));
    }

    public static function lateNoticeLink(Lease $lease, Collection $invoices, string $channel): string
    {
        return $channel === 'sms'
            ? self::smsUrl($lease->tenant->phone, self::lateNoticeSms($lease, $invoices))
            : self::whatsappUrl($lease->tenant->phone, self::lateNotice($lease, $invoices));
    }

    // Depuis le navigateur, on redirige directement vers WhatsApp ou les SMS. Depuis l'application installée,
    // Android bloque l'ouverture d'une autre application après une redirection : on affiche une page avec
    // un bouton à toucher, qui ouvre le message.
    public static function open(string $url): Response
    {
        if (request()->input('via') !== 'app') {
            return redirect()->away($url);
        }

        return response()->view('messages.open', [
            'url' => $url,
            'launch' => self::launchUrl($url, (string) request()->userAgent()),
            'channel' => str_starts_with($url, 'sms:') ? 'sms' : 'whatsapp',
            'back' => url()->previous(),
        ]);
    }

    // Sur Android, l'adresse « intent » ouvre WhatsApp ou WhatsApp Business (au choix s'il y a les deux),
    // et revient au lien wa.me si aucun n'est installé.
    public static function launchUrl(string $url, string $userAgent): string
    {
        if (! str_contains($userAgent, 'Android') || ! preg_match('#^https://wa\.me/(\d+)\?text=(.*)$#s', $url, $parts)) {
            return $url;
        }

        return 'intent://send/?phone='.$parts[1].'&text='.$parts[2]
            .'#Intent;scheme=whatsapp;S.browser_fallback_url='.rawurlencode($url).';end';
    }

    private static function dueSentence(Invoice $invoice): string
    {
        $days = (int) today()->diffInDays($invoice->due_date);

        return match (true) {
            $days <= 0 => __('Votre loyer arrive à échéance *aujourd\'hui*.'),
            $days === 1 => __('Votre loyer arrive à échéance *demain, le :v1*.', ['v1' => $invoice->due_date->format('d/m/Y')]),
            default => __('Votre loyer arrive à échéance dans :v1 jours, le *:v2*.', ['v1' => $days, 'v2' => $invoice->due_date->format('d/m/Y')]),
        };
    }

    private static function whatsappUrl(string $phone, string $message): string
    {
        return 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($message);
    }

    // « ?& » : la forme acceptée à la fois par Android et par iPhone.
    private static function smsUrl(string $phone, string $message): string
    {
        return 'sms:'.$phone.'?&body='.rawurlencode($message);
    }

    // Ce qui reste dû sur les autres mois de ce bail : le total des SMS reprend tous les mois impayés.
    private static function arrears(Invoice $invoice): float
    {
        return (float) Invoice::unpaid()
            ->whereKeyNot($invoice->id)
            ->where('lease_id', $invoice->lease_id)
            ->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')
            ->value('total');
    }

    // Une ligne par mois dû, avec ce qui reste à payer sur ce mois.
    private static function monthLines(Collection $months): array
    {
        return $months->map(fn (Invoice $month) => '• '.$month->label().' : '.money($month->balance())
            .($month->isOverdue() ? ' ('.__('en retard').')' : ''))->values()->all();
    }

    private static function totalLines(Collection $months, ?string $closing = null): array
    {
        $overdue = $months->filter(fn (Invoice $month) => $month->isOverdue());
        $lines = ['*'.__('Total à payer : :v1', ['v1' => money($months->sum(fn (Invoice $month) => $month->balance()))]).'*'];

        if ($overdue->isNotEmpty() && $overdue->count() < $months->count()) {
            $lines[] = __('Dont en retard : :v1', ['v1' => money($overdue->sum(fn (Invoice $month) => $month->balance()))]);
        }

        return $closing ? [...$lines, $closing] : $lines;
    }

    // Dans un SMS, plusieurs mois tiennent en une plage : les paiements règlent des mois qui se suivent.
    private static function coveredRange(Payment $payment): string
    {
        $months = $payment->allocations->sortBy(fn ($share) => $share->invoice->period)->values();

        return $months->count() > 2
            ? __(':v1 à :v2', ['v1' => $months->first()->invoice->label(), 'v2' => $months->last()->invoice->label()])
            : $payment->coveredLabel();
    }

    // Rien pour un bail au mois : les messages restent les mêmes.
    private static function billingLine(Lease $lease): array
    {
        return (int) $lease->billing_months > 1 ? [__('Facturation : :v1', ['v1' => mb_strtolower($lease->billingLabel())])] : [];
    }

    private static function greeting(Lease $lease): string
    {
        return __('Bonjour :v1 *:v2*,', ['v1' => $lease->tenant->title(), 'v2' => $lease->tenant->name]);
    }

    /**
     * La mise en forme commune des messages WhatsApp : un titre, le détail, puis le montant à
     * retenir encadré. Seul le texte passe dans un message pré-rempli : le filet est un caractère
     * ordinaire, sans émoji, car ceux-ci arrivent cassés depuis un ordinateur.
     */
    private static function card(string $title, array $intro, array $details, array $highlight, string $note, array $months = []): string
    {
        $organisation = auth()->user()->organisation;

        return implode("\n", array_filter([
            '*'.mb_strtoupper($title).'*',
            self::RULE,
            '',
            ...$intro,
            '',
            ...array_map(fn (string $line) => '• '.$line, $details),
            ...($months ? ['', '*'.__('Reste à payer par mois :').'*', ...$months] : []),
            '',
            self::RULE,
            ...$highlight,
            self::RULE,
            '',
            '_'.$note.'_',
            '',
            '*'.$organisation->name.'*',
            $organisation->phone ? __('Tél. : :v1', ['v1' => $organisation->phone]) : null,
        ], fn ($line) => $line !== null));
    }

    private static function prefix(): string
    {
        return auth()->user()->organisation->name.' - ';
    }

    private static function finishSms(string $text): string
    {
        $phone = auth()->user()->organisation->phone;

        return Str::ascii($phone ? $text.' '.__('Tél. : :v1', ['v1' => $phone]) : $text);
    }
}
