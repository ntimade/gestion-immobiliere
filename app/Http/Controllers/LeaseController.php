<?php

namespace App\Http\Controllers;

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\RentService;
use App\Support\PhoneNumber;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LeaseController extends Controller
{
    private const PERIOD_RULE = 'regex:/^\d{4}-(0[1-9]|1[0-2])$/';

    public function __construct(private RentService $rent)
    {
    }

    public function create(Unit $unit): View|RedirectResponse
    {
        if ($unit->leases()->whereNull('end_date')->whereNull('notice_end_date')->exists()) {
            return redirect()->route('units.show', $unit)->with('error', __('Ce logement est déjà occupé. Enregistrez d\'abord la sortie de son locataire.'));
        }

        // Après un préavis, la nouvelle entrée commence au plus tôt le lendemain du départ.
        $lastDay = $unit->leases()->selectRaw('MAX(COALESCE(end_date, notice_end_date)) as last_day')->value('last_day');
        $earliest = $lastDay ? Carbon::parse($lastDay)->addDay() : null;

        return view('leases.create', [
            'unit' => $unit->load('property'),
            'tenants' => Tenant::orderBy('name')->get(),
            'earliestStart' => $earliest && $earliest->gt(today()) ? $earliest : null,
        ]);
    }

    public function store(Request $request, Unit $unit): RedirectResponse
    {
        $organisationId = $request->user()->organisation_id;

        $data = $request->validate([
            'tenant_mode' => ['required', Rule::in(['new', 'existing'])],
            'tenant_id' => ['exclude_unless:tenant_mode,existing', 'required', Rule::exists('tenants', 'id')->where('organisation_id', $organisationId)],
            'start_date' => ['required', 'date'],
            'first_period' => ['nullable', self::PERIOD_RULE, function ($attribute, $value, $fail) use ($request) {
                if ($value < substr((string) $request->input('start_date'), 0, 7)) {
                    $fail(__('Le premier mois facturé ne peut pas être antérieur au mois d\'entrée.'));
                }
            }],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'billing_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'rent' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'deposit_enabled' => ['nullable', 'boolean'],
            'deposit_months' => ['nullable', 'integer', 'min:0', 'max:24'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'deposit_paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'entry_months' => ['nullable', 'integer', 'min:1', 'max:'.(RentService::MAX_MONTHS_AHEAD + 24)],
            'entry_method' => ['nullable', 'string', 'max:50'],
            'entry_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ] + ($request->input('tenant_mode') === 'new' ? TenantController::rules('tenant.') : []), self::futurePaymentMessages(), self::attributes());

        $data = $this->withDeposit($data);
        $advance = $this->rentAdvance($data);

        // Tout est créé ensemble : une entrée refusée n'ajoute pas de locataire.
        [$lease, $payment] = DB::transaction(function () use ($data, $unit, $request, $advance) {
            $tenant = $data['tenant_mode'] === 'existing'
                ? Tenant::findOrFail($data['tenant_id'])
                : Tenant::create(['phone' => PhoneNumber::forStorage($data['tenant']['phone'] ?? null, $request->user()->organisation->phone_country)] + $data['tenant']);

            $lease = $this->rent->startLease($unit, $tenant, $data + ['entry_advance' => $advance > 0]);

            // La caution reste sur le bail : seul le reste du versement règle des mois de loyer.
            $payment = null;
            if ($advance > 0) {
                $payment = $this->rent->recordPayment($lease, $advance, $data['entry_paid_at'], $data['entry_method'] ?? null, $data['entry_reference'] ?? null);
                $this->rent->syncLease($lease);
            }

            return [$lease, $payment];
        });

        $redirect = redirect()->route('leases.show', $lease);

        if (! empty($data['entry_months'])) {
            return $redirect->with('notify_payment', $payment?->id)->with('success', __('Entrée enregistrée. Sur :v1 versés : :v2 de caution et :v3 de loyers d\'avance.', [
                'v1' => money($advance + (float) $data['deposit_amount']),
                'v2' => money($data['deposit_amount']),
                'v3' => money($advance),
            ]).($lease->paidThrough() ? ' '.__('Loyer payé jusqu\'au :v1', ['v1' => $lease->paidThrough()->format('d/m/Y')]).'.' : ''));
        }

        return $redirect->with('success', __('Entrée enregistrée. Les factures de loyer sont créées à partir de :v1.', ['v1' => period_label($lease->first_period)]));
    }

    // La caution se déduit du nombre de mois négocié, sauf montant saisi. Désactivée, elle ne compte plus.
    private function withDeposit(array $data): array
    {
        if (array_key_exists('deposit_enabled', $data) && ! $data['deposit_enabled']) {
            $data['entry_paid_at'] = $this->entryDate($data);

            return array_merge($data, ['deposit_months' => 0, 'deposit_amount' => 0, 'deposit_paid_at' => null]);
        }

        // La caution compte toujours un nombre entier de mois de loyer : le reste du versement en compte donc aussi.
        if (isset($data['deposit_months'])) {
            $data['deposit_amount'] = round((float) $data['rent'] * (int) $data['deposit_months'], 3);
        } elseif ((float) ($data['deposit_amount'] ?? 0) > 0) {
            $data['deposit_months'] = $this->monthsOfRent((float) $data['deposit_amount'], (float) $data['rent']);
        }

        $data['deposit_amount'] ??= 0;
        $data['entry_paid_at'] = $this->entryDate($data);

        return $data;
    }

    private function monthsOfRent(float $amount, float $rent): int
    {
        $months = $rent > 0 ? (int) round($amount / $rent) : 0;

        if ($months < 1 || round($months * $rent, 3) !== round($amount, 3)) {
            throw ValidationException::withMessages(['deposit_amount' => __('La caution doit être un multiple du loyer (:v1).', ['v1' => money($rent)])]);
        }

        return $months;
    }

    // Un seul versement à l'entrée, donc une seule date : la caution est retenue, le reste part dans les loyers.
    private function entryDate(array $data): string
    {
        return $data['deposit_paid_at'] ?? $data['start_date'];
    }

    private static function futurePaymentMessages(): array
    {
        return ['deposit_paid_at.before_or_equal' => __('Un versement reçu ne peut pas être daté dans le futur.')];
    }

    // Ce qui part en loyers d'avance : le versement d'entrée, moins la caution.
    private function rentAdvance(array $data): float
    {
        if (empty($data['entry_months'])) {
            return 0;
        }

        if ($data['entry_paid_at'] > today()->toDateString()) {
            throw ValidationException::withMessages(['deposit_paid_at' => __('Un versement reçu ne peut pas être daté dans le futur.')]);
        }

        $total = round((float) $data['rent'] * (int) $data['entry_months'], 3);
        $deposit = (float) $data['deposit_amount'];

        if ($total < $deposit) {
            throw ValidationException::withMessages(['entry_months' => __('Le versement d\'entrée (:v1) est inférieur à la caution (:v2).', ['v1' => money($total), 'v2' => money($deposit)])]);
        }

        return round($total - $deposit, 3);
    }

    public function show(Lease $lease): View
    {
        $lease->load(['tenant', 'unit.property']);

        return view('leases.show', [
            'lease' => $lease,
            'unpaid' => $lease->unpaidTotal(),
            'justPaid' => session('notify_payment') ? $lease->payments()->with('lease.tenant')->find(session('notify_payment')) : null,
            'invoices' => SortedTable::query($lease->invoices()->orderByDesc('period'), 'factures', [
                'period' => 'period',
                'amount' => 'amount',
                'paid' => 'paid_amount',
                'due' => 'due_date',
            ]),
            'payments' => SortedTable::query($lease->payments()->with(['allocations.invoice', 'lease.tenant'])->latest('paid_at')->latest('id'), 'paiements', [
                'date' => 'paid_at',
                'method' => 'method',
                'reference' => 'reference',
                'amount' => 'amount',
            ]),
        ]);
    }

    public function edit(Lease $lease): View
    {
        return view('leases.edit', ['lease' => $lease->load(['tenant', 'unit.property'])]);
    }

    public function update(Request $request, Lease $lease): RedirectResponse
    {
        $data = $request->validate([
            'start_date' => ['sometimes', 'required', 'date'],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'billing_months' => ['sometimes', 'required', 'integer', 'min:1', 'max:24'],
            'rent' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'deposit_months' => ['nullable', 'integer', 'min:0', 'max:24'],
            'deposit_amount' => ['required', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'deposit_paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], self::attributes());

        // Un montant inchangé reste accepté : le loyer a pu évoluer depuis le versement de la caution.
        if ((float) $data['deposit_amount'] !== (float) $lease->deposit_amount) {
            $data['deposit_months'] = (float) $data['deposit_amount'] > 0
                ? $this->monthsOfRent((float) $data['deposit_amount'], (float) $data['rent'])
                : 0;
        }

        $this->rent->updateLease($lease, $data);

        return redirect()->route('leases.show', $lease)->with('success', __('Bail modifié. Le loyer et le jour d\'échéance s\'appliquent aux prochaines factures.'));
    }

    public function exit(Lease $lease): View|RedirectResponse
    {
        if ($lease->end_date) {
            return redirect()->route('leases.show', $lease)->with('error', __('La sortie de ce locataire est déjà enregistrée.'));
        }

        return view('leases.exit', ['lease' => $lease->load(['tenant', 'unit.property']), 'unpaid' => $lease->unpaidTotal()]);
    }

    public function end(Request $request, Lease $lease): RedirectResponse
    {
        if ($lease->end_date) {
            return redirect()->route('leases.show', $lease)->with('error', __('La sortie de ce locataire est déjà enregistrée.'));
        }

        $data = $request->validate([
            'end_date' => ['required', 'date'],
            'deposit_mode' => ['nullable', Rule::in(['complete', 'partial'])],
            'deposit_retained' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'deposit_refunded' => ['nullable', 'required_if:deposit_mode,partial', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'deposit_refunded_on' => ['nullable', 'date'],
            'deposit_note' => ['nullable', 'string', 'max:255'],
            'deposit_to_rent' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
        ], [], self::attributes());

        $amount = (float) $lease->deposit_amount;

        // Rendue en entier, ou en partie : dans ce cas, le reste de la caution est retenu et justifié.
        if (($data['deposit_mode'] ?? null) === 'complete') {
            $data = array_merge($data, ['deposit_refunded' => $amount, 'deposit_retained' => 0, 'deposit_note' => null, 'deposit_to_rent' => 0]);
        } elseif (($data['deposit_mode'] ?? null) === 'partial') {
            if ((float) $data['deposit_refunded'] >= $amount) {
                throw ValidationException::withMessages(['deposit_refunded' => __('Un remboursement partiel doit être inférieur à la caution (:v1).', ['v1' => money($amount)])]);
            }

            $data['deposit_retained'] = round($amount - (float) $data['deposit_refunded'], 3);
        }

        $this->rent->endLease($lease, $data);

        return redirect()->route('leases.show', $lease)->with('success', __('Sortie enregistrée.'));
    }

    public function notice(Request $request, Lease $lease): RedirectResponse
    {
        $data = $request->validate([
            'notice_given_on' => ['required', 'date', 'before_or_equal:today'],
            'notice_end_date' => ['required', 'date'],
        ], [], self::attributes());

        $this->rent->giveNotice($lease, $data['notice_given_on'], $data['notice_end_date']);

        return redirect()->route('leases.show', $lease)->with('success', __('Préavis enregistré : départ le :v1, la facturation s\'arrête après ce mois.', ['v1' => $lease->fresh()->notice_end_date->format('d/m/Y')]));
    }

    public function cancelNotice(Lease $lease): RedirectResponse
    {
        $this->rent->cancelNotice($lease);

        return redirect()->route('leases.show', $lease)->with('success', __('Préavis annulé : le bail continue.'));
    }

    public function inspection(Request $request, Lease $lease): RedirectResponse
    {
        if (! $lease->onNotice()) {
            return redirect()->route('leases.show', $lease)->with('error', __('Enregistrez d\'abord le préavis du locataire.'));
        }

        $this->rent->recordInspection($lease, $request->validate([
            'inspection_at' => ['nullable', 'date'],
            'inspection_report' => ['nullable', 'string', 'max:2000'],
            'inspection_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
        ], [], self::attributes()));

        return redirect()->route('leases.show', $lease)->with('success', __('État des lieux enregistré.'));
    }

    public function reopen(Lease $lease): RedirectResponse
    {
        $this->rent->reopenLease($lease);

        return redirect()->route('leases.show', $lease)->with('success', __('Sortie annulée : le bail reprend.'));
    }

    private static function attributes(): array
    {
        return [
            'tenant_id' => __('locataire'),
            'tenant.name' => __('nom du locataire'),
            'tenant.phone' => __('téléphone'),
            'tenant.email' => __('e-mail'),
            'start_date' => __('date d\'entrée'),
            'first_period' => __('premier mois facturé'),
            'due_day' => __('jour d\'échéance'),
            'billing_months' => __('cycle de facturation'),
            'rent' => __('montant du loyer'),
            'deposit_amount' => __('caution'),
            'deposit_months' => __('mois de caution'),
            'entry_months' => __('mois versés à l\'entrée'),
            'entry_method' => __('mode de paiement'),
            'entry_reference' => __('référence'),
            'deposit_paid_at' => __('date du versement'),
            'end_date' => __('date de sortie'),
            'deposit_retained' => __('caution retenue'),
            'deposit_refunded' => __('caution rendue'),
            'deposit_refunded_on' => __('date du remboursement'),
            'deposit_note' => __('justification'),
            'deposit_to_rent' => __('part affectée aux loyers impayés'),
            'notice_given_on' => __('date du préavis'),
            'notice_end_date' => __('date de départ'),
            'inspection_at' => __('date de l\'état des lieux'),
            'inspection_report' => __('constats'),
            'inspection_cost' => __('coût des réfections'),
        ];
    }
}
