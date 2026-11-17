<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\SubscriptionPayment;
use App\Models\SupportAction;
use App\Models\SupportAdmin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    // Valide un paiement déclaré. Le verrou empêche de prolonger deux fois pour un même paiement.
    public function approve(SubscriptionPayment $payment, SupportAdmin $admin): bool
    {
        return DB::transaction(function () use ($payment, $admin) {
            $locked = SubscriptionPayment::lockForUpdate()->find($payment->id);

            if (! $locked?->isPending()) {
                return false;
            }

            [$start, $end] = $this->extend($locked->organisation_id, $locked->months);

            $locked->update([
                'status' => SubscriptionPayment::APPROVED,
                'period_start' => $start,
                'period_end' => $end,
                'handled_by' => $admin->id,
                'handled_at' => now(),
            ]);

            $this->log($admin, $locked->organisation_id, SupportAction::SUBSCRIPTION_EXTENDED, $locked->methodLabel().' : +'.$locked->months);

            return true;
        });
    }

    public function reject(SubscriptionPayment $payment, SupportAdmin $admin, ?string $note): bool
    {
        return DB::transaction(function () use ($payment, $admin, $note) {
            $locked = SubscriptionPayment::lockForUpdate()->find($payment->id);

            if (! $locked?->isPending()) {
                return false;
            }

            $locked->update(['status' => SubscriptionPayment::REJECTED, 'handled_by' => $admin->id, 'handled_at' => now(), 'note' => $note]);

            $this->log($admin, $locked->organisation_id, SupportAction::SUBSCRIPTION_REJECTED, $note);

            return true;
        });
    }

    // Prolongation directe par le super admin, par exemple après un paiement en espèces.
    public function grant(Organisation $organisation, SupportAdmin $admin, array $data): SubscriptionPayment
    {
        return DB::transaction(function () use ($organisation, $admin, $data) {
            [$start, $end] = $this->extend($organisation->id, (int) $data['months']);

            $payment = SubscriptionPayment::create([
                'organisation_id' => $organisation->id,
                'months' => $data['months'],
                'amount' => $data['amount'] ?? null,
                'currency' => isset($data['amount']) ? ($data['currency'] ?? null) : null,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'status' => SubscriptionPayment::APPROVED,
                'period_start' => $start,
                'period_end' => $end,
                'handled_by' => $admin->id,
                'handled_at' => now(),
                'note' => $data['note'] ?? null,
            ]);

            $this->log($admin, $organisation->id, SupportAction::SUBSCRIPTION_EXTENDED, $payment->methodLabel().' : +'.$payment->months);

            return $payment;
        });
    }

    /**
     * Un abonnement encore valable continue à partir de sa fin : le temps restant n'est pas perdu.
     * Un abonnement expiré ou absent repart d'aujourd'hui.
     */
    private function extend(int $organisationId, int $months): array
    {
        $organisation = Organisation::lockForUpdate()->findOrFail($organisationId);
        $current = $organisation->subscribed_until;
        $running = $current && $current->gte(today());

        $start = $running ? $current->copy()->addDay() : Carbon::today();
        $end = ($running ? $current->copy() : Carbon::today())->addMonthsNoOverflow($months);

        $organisation->forceFill(['subscribed_until' => $end])->save();

        return [$start, $end];
    }

    private function log(SupportAdmin $admin, int $organisationId, string $action, ?string $note): void
    {
        SupportAction::create(['support_admin_id' => $admin->id, 'organisation_id' => $organisationId, 'action' => $action, 'note' => $note]);
    }
}
