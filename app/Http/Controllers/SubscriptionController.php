<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $organisation = $request->user()->organisation;

        return view('subscription.index', [
            'organisation' => $organisation,
            'plans' => SubscriptionPlan::available()->get(),
            'allPlans' => SubscriptionPlan::orderBy('months')->get(),
            'cashInstructions' => PlatformSetting::get('cash_instructions'),
            'pending' => SubscriptionPayment::where('organisation_id', $organisation->id)->where('status', SubscriptionPayment::PENDING)->latest()->first(),
            'payments' => SortedTable::query(
                SubscriptionPayment::where('organisation_id', $organisation->id)->latest()->latest('id'),
                'abonnements',
                ['date' => 'created_at', 'months' => 'months', 'amount' => 'amount', 'method' => 'method', 'status' => 'status', 'end' => 'period_end'],
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organisation = $request->user()->organisation;

        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)->whereNotNull('price')],
            'method' => ['required', Rule::in(SubscriptionPayment::AVAILABLE_METHODS)],
        ], [
            'method.in' => __('Ce mode de paiement n\'est pas disponible pour le moment. Payez en espèces.'),
        ], [
            'plan_id' => __('plan'),
            'method' => __('mode de paiement'),
        ]);

        if (SubscriptionPayment::where('organisation_id', $organisation->id)->where('status', SubscriptionPayment::PENDING)->exists()) {
            return back()->with('error', __('Un paiement est déjà en attente de validation.'));
        }

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        SubscriptionPayment::create([
            'organisation_id' => $organisation->id,
            'plan_id' => $plan->id,
            'requested_by' => $request->user()->id,
            'months' => $plan->months,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'method' => $data['method'],
        ]);

        return redirect()->route('subscription.index')->with('success', __('Votre demande est enregistrée. L\'abonnement sera activé dès réception de votre paiement en espèces.'));
    }
}
