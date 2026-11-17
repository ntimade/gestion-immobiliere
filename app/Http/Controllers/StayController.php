<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Lease;
use App\Models\Stay;
use App\Models\Unit;
use App\Support\InvoiceMessage;
use App\Support\PhoneNumber;
use App\Support\SortedTable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Location à la nuit des logements meublés. Un séjour se compte par tranches de 24 heures, une nuit au
 * minimum : ce n'est pas un hôtel à l'heure. Le client et son paiement sont enregistrés ensemble ; la
 * facture s'imprime et le remerciement WhatsApp donne l'heure de libération du logement.
 */
class StayController extends Controller
{
    public const MAX_DAYS = 365;

    private const RELATIONS = ['guest', 'unit.property'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);

        return view('stays.index', [
            'filters' => $filters,
            'stays' => SortedTable::query((clone $query)->with(self::RELATIONS)->orderByDesc('check_in_at')->orderByDesc('id'), 'sejours', [
                'arrival' => 'check_in_at',
                'departure' => 'check_out_at',
                'amount' => 'amount',
                'paid' => 'paid_amount',
            ]),
            'totals' => $this->totals($query),
            'guests' => Guest::orderBy('name')->get(['id', 'name', 'phone']),
            'units' => Unit::with('property')->rentableByNight()->get()->sortBy(fn (Unit $unit) => $unit->label())->values(),
            'hasNightUnits' => Unit::rentableByNight()->exists(),
        ]);
    }

    public function create(Request $request): View
    {
        $units = Unit::with(['property', 'currentStay.guest'])->rentableByNight()->get()->sortBy(fn (Unit $unit) => $unit->label())->values();

        return view('stays.create', [
            'units' => $units,
            'selectedUnit' => $units->firstWhere('id', (int) $request->query('unit')),
            'guests' => Guest::orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organisation = $request->user()->organisation;
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'guest_id' => ['nullable', 'integer'],
            'name' => ['required_without:guest_id', 'nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'in:M,F'],
            'phone' => ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) {
                if (! PhoneNumber::isValid($value)) {
                    $fail(__('Saisissez un seul numéro de téléphone, avec des chiffres uniquement.'));
                }
            }],
            'id_number' => ['nullable', 'string', 'max:100'],
            'check_in_at' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'daily_rate' => ['required', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'paid_amount' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,3'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'days.min' => __('Un séjour dure au moins une nuit (24 h).'),
        ], [
            'unit_id' => __('logement'),
            'name' => __('nom du client'),
            'check_in_at' => __('arrivée'),
            'days' => __('nombre de jours'),
            'daily_rate' => __('tarif journalier'),
            'paid_amount' => __('montant payé'),
        ]);

        $checkIn = Carbon::parse($data['check_in_at'])->seconds(0);
        $days = (int) $data['days'];
        $checkOut = Stay::checkOutFor($checkIn, $days);
        $amount = round($days * (float) $data['daily_rate'], 3);

        if ((float) $data['paid_amount'] > $amount) {
            throw ValidationException::withMessages(['paid_amount' => __('Le montant payé dépasse le montant du séjour (:v1).', ['v1' => money($amount)])]);
        }

        $stay = DB::transaction(function () use ($request, $data, $checkIn, $checkOut, $days, $amount, $organisation) {
            $unit = Unit::whereKey($data['unit_id'])->lockForUpdate()->first();

            if (! $unit || ! $unit->rentsByNight()) {
                throw ValidationException::withMessages(['unit_id' => __('Choisissez un logement meublé qui a un tarif journalier.')]);
            }

            $this->ensureFree($unit, $checkIn, $checkOut);

            $guest = $this->guest($data, $organisation->phone_country);

            return Stay::create([
                'unit_id' => $unit->id,
                'guest_id' => $guest->id,
                'user_id' => $request->user()->id,
                'check_in_at' => $checkIn,
                'days' => $days,
                'check_out_at' => $checkOut,
                'daily_rate' => $data['daily_rate'],
                'amount' => $amount,
                'paid_amount' => $data['paid_amount'],
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return redirect()->route('stays.show', $stay)->with('success', __('Séjour enregistré. Envoyez maintenant le message de remerciement au client.'));
    }

    public function show(Stay $stay): View
    {
        return view('stays.show', ['stay' => $stay->load([...self::RELATIONS, 'user'])]);
    }

    // Un complément de paiement, quand le client n'a pas tout réglé à l'arrivée.
    public function pay(Request $request, Stay $stay): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,3'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [], ['amount' => __('montant')]);

        if ((float) $data['amount'] > $stay->balance()) {
            throw ValidationException::withMessages(['amount' => __('Le reste à payer est de :v1.', ['v1' => money($stay->balance())])]);
        }

        $stay->update([
            'paid_amount' => round((float) $stay->paid_amount + (float) $data['amount'], 3),
            'method' => ($data['method'] ?? null) ?: $stay->method,
            'reference' => ($data['reference'] ?? null) ?: $stay->reference,
        ]);

        return back()->with('success', __('Paiement ajouté.'));
    }

    // La facture du séjour, à imprimer et à remettre au client.
    public function invoice(Request $request, Stay $stay): View
    {
        return view('stays.invoice', [
            'stays' => collect([$stay->load(self::RELATIONS)]),
            'title' => __('Facture ').$stay->number(),
            'organisation' => $request->user()->organisation,
            'logoSrc' => route('settings.logo', ['v' => $request->user()->organisation->updated_at?->timestamp]),
            'backUrl' => route('stays.show', $stay),
        ]);
    }

    public function invoicePdf(Request $request, Stay $stay): HttpResponse
    {
        return $this->pdf('stays.invoice-pdf', [
            'stays' => collect([$stay->load(self::RELATIONS)]),
            'title' => __('Facture ').$stay->number(),
        ], Str::slug(__('Facture').' '.$stay->number()));
    }

    // L'historique filtré (client, logement, période), à imprimer.
    public function report(Request $request): View
    {
        $filters = $this->filters($request);

        return view('stays.report', $this->reportData($filters) + [
            'organisation' => $request->user()->organisation,
            'logoSrc' => route('settings.logo', ['v' => $request->user()->organisation->updated_at?->timestamp]),
            'backUrl' => route('stays.index', $request->query()),
        ]);
    }

    public function reportPdf(Request $request): HttpResponse
    {
        return $this->pdf('stays.report-pdf', $this->reportData($this->filters($request)), Str::slug(__('Historique des séjours').' '.now()->format('Y-m-d')));
    }

    // On note l'ouverture de l'application : on ne peut pas savoir si le message est parti.
    public function message(Stay $stay, string $channel): Response
    {
        $stay->load(self::RELATIONS);

        if (! PhoneNumber::isInternational($stay->guest->phone)) {
            return back()->with('error', __('Le numéro de ce client est absent ou à corriger.'));
        }

        $stay->forceFill([$channel.'_opened_at' => now()])->save();

        return InvoiceMessage::open(InvoiceMessage::stayLink($stay, $channel));
    }

    /**
     * Le logement doit être libre pendant tout le séjour : ni un autre séjour, ni un locataire au mois.
     * Un départ à 14 h libère le logement pour une arrivée à 14 h.
     */
    private function ensureFree(Unit $unit, Carbon $checkIn, Carbon $checkOut): void
    {
        $other = Stay::with('guest')->where('unit_id', $unit->id)->overlapping($checkIn, $checkOut)->orderBy('check_in_at')->first();
        if ($other) {
            throw ValidationException::withMessages(['check_in_at' => __('Ce logement est déjà réservé par :v1 du :v2 au :v3.', [
                'v1' => $other->guest->name,
                'v2' => $other->check_in_at->format('d/m/Y H:i'),
                'v3' => $other->check_out_at->format('d/m/Y H:i'),
            ])]);
        }

        $lease = Lease::with('tenant')->where('unit_id', $unit->id)
            ->where('start_date', '<=', $checkOut->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereRaw('COALESCE(end_date, notice_end_date) IS NULL')
                ->orWhereRaw('COALESCE(end_date, notice_end_date) >= ?', [$checkIn->toDateString()]))
            ->first();
        if ($lease) {
            throw ValidationException::withMessages(['check_in_at' => __('Ce logement est loué au mois à :v1 sur ces dates.', ['v1' => $lease->tenant->name])]);
        }
    }

    // Un client déjà connu est repris : par son choix dans la liste, ou par son numéro de téléphone.
    private function guest(array $data, ?string $country): Guest
    {
        $phone = PhoneNumber::forStorage($data['phone'] ?? null, $country);
        $details = array_filter([
            'name' => $data['name'] ?? null,
            'gender' => $data['gender'] ?? null,
            'phone' => $phone,
            'id_number' => $data['id_number'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $guest = ! empty($data['guest_id'])
            ? Guest::findOrFail($data['guest_id'])
            : ($phone ? Guest::where('phone', $phone)->first() : null);

        if ($guest) {
            $guest->update($details);

            return $guest;
        }

        return Guest::create($details);
    }

    private function filters(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? $request->query($key) : null;

        return [
            'guest_id' => ctype_digit((string) $request->query('guest_id')) ? (int) $request->query('guest_id') : null,
            'unit_id' => ctype_digit((string) $request->query('unit_id')) ? (int) $request->query('unit_id') : null,
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    // La période retient les séjours qui la touchent, même commencés avant ou finis après.
    private function filtered(array $filters): Builder
    {
        return Stay::query()
            ->when($filters['guest_id'], fn (Builder $query, int $id) => $query->where('guest_id', $id))
            ->when($filters['unit_id'], fn (Builder $query, int $id) => $query->where('unit_id', $id))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('check_out_at', '>', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('check_in_at', '<', Carbon::parse($to)->addDay()->startOfDay()));
    }

    private function totals(Builder $query): array
    {
        $row = (clone $query)->selectRaw('COUNT(*) as count, COALESCE(SUM(days), 0) as days, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(paid_amount), 0) as paid')->first();

        return [
            'count' => (int) $row->count,
            'days' => (int) $row->days,
            'amount' => round((float) $row->amount, 3),
            'paid' => round((float) $row->paid, 3),
            'balance' => round((float) $row->amount - (float) $row->paid, 3),
        ];
    }

    private function reportData(array $filters): array
    {
        $query = $this->filtered($filters);

        return [
            'stays' => (clone $query)->with(self::RELATIONS)->orderBy('check_in_at')->orderBy('id')->get(),
            'totals' => $this->totals($query),
            'guest' => $filters['guest_id'] ? Guest::find($filters['guest_id']) : null,
            'unit' => $filters['unit_id'] ? Unit::with('property')->find($filters['unit_id']) : null,
            'filters' => $filters,
            'title' => __('Historique des séjours'),
        ];
    }

    private function pdf(string $view, array $data, string $filename): HttpResponse
    {
        $organisation = auth()->user()->organisation;
        $pdf = new Dompdf(new Options(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]));
        $pdf->loadHtml(view($view, $data + ['organisation' => $organisation, 'logoSrc' => $organisation->logoDataUri()])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
