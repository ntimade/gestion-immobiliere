<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $all = $request->query('etat') === 'tous';

        return view('support.subscriptions.index', [
            'all' => $all,
            'pendingCount' => SubscriptionPayment::where('status', SubscriptionPayment::PENDING)->count(),
            'payments' => SortedTable::query(
                SubscriptionPayment::with('organisation')
                    ->when(! $all, fn ($q) => $q->where('status', SubscriptionPayment::PENDING))
                    ->latest()->latest('id'),
                'paiements',
                [
                    'date' => 'created_at',
                    'organisation' => SortedTable::related('organisations', 'name', 'id', 'subscription_payments.organisation_id'),
                    'months' => 'months',
                    'amount' => 'amount',
                    'method' => 'method',
                    'end' => 'period_end',
                    'status' => 'status',
                ],
            ),
        ]);
    }

    public function settings(): View
    {
        return view('support.subscriptions.settings', [
            'plans' => SubscriptionPlan::orderBy('months')->get(),
            'currencies' => config('currencies'),
            'cashInstructions' => PlatformSetting::get('cash_instructions'),
            'trialDays' => PlatformSetting::get('trial_days') ?? config('subscription.trial_days'),
        ]);
    }

    public function updatePlans(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plans' => ['required', 'array'],
            'plans.*.price' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'plans.*.currency' => ['required', Rule::in(array_keys(config('currencies')))],
            'plans.*.is_active' => ['nullable', 'boolean'],
        ], [], ['plans.*.price' => __('prix'), 'plans.*.currency' => __('devise')]);

        $plans = SubscriptionPlan::whereIn('id', array_keys($data['plans']))->get();

        foreach ($plans as $plan) {
            $values = $data['plans'][$plan->id];
            $active = (bool) ($values['is_active'] ?? false);
            $price = $values['price'] ?? null;

            if ($active && $price === null) {
                return back()->withInput()->withErrors(["plans.{$plan->id}.price" => __('Indiquez un prix pour activer ce plan.')]);
            }
        }

        foreach ($plans as $plan) {
            $values = $data['plans'][$plan->id];
            $plan->update(['price' => $values['price'] ?? null, 'currency' => $values['currency'], 'is_active' => (bool) ($values['is_active'] ?? false)]);
        }

        $response = back()->with('success', __('Plans enregistrés. Les paiements déjà déclarés gardent leur prix.'));

        // Oubli fréquent : un prix sans la case Actif reste invisible pour les utilisateurs.
        return SubscriptionPlan::where('is_active', false)->whereNotNull('price')->exists()
            ? $response->with('error', __('Un plan avec un prix mais sans la case Actif reste invisible pour les utilisateurs.'))
            : $response;
    }

    public function updateMethods(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cash_instructions' => ['nullable', 'string', 'max:500'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
        ], [], ['trial_days' => __('durée de l\'essai')]);

        PlatformSetting::put('cash_instructions', $data['cash_instructions'] ?? null);
        PlatformSetting::put('trial_days', (string) $data['trial_days']);

        return back()->with('success', __('Modes de paiement enregistrés.'));
    }

    public function approve(Request $request, SubscriptionPayment $payment, SubscriptionService $subscriptions): RedirectResponse
    {
        return $subscriptions->approve($payment, $request->user('support'))
            ? back()->with('success', __('Paiement validé. L\'abonnement est prolongé.'))
            : back()->with('error', __('Ce paiement a déjà été traité.'));
    }

    public function reject(Request $request, SubscriptionPayment $payment, SubscriptionService $subscriptions): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return $subscriptions->reject($payment, $request->user('support'), $note)
            ? back()->with('success', __('Paiement refusé.'))
            : back()->with('error', __('Ce paiement a déjà été traité.'));
    }

    public function extend(Request $request, Organisation $organisation, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'method' => ['required', Rule::in(SubscriptionPayment::METHODS)],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'currency' => ['nullable', Rule::in(array_keys(config('currencies')))],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'months' => __('nombre de mois'),
            'method' => __('mode de paiement'),
            'amount' => __('montant'),
            'reference' => __('référence de la transaction'),
        ]);

        $subscriptions->grant($organisation, $request->user('support'), $data);

        return redirect()->route('support.accounts.show', $organisation->id)
            ->with('success', __('Abonnement prolongé jusqu\'au :v1.', ['v1' => $organisation->fresh()->subscribed_until->format('d/m/Y')]));
    }
}
