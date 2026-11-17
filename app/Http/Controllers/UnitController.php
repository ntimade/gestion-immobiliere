<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Unit;
use App\Rules\MonthlyRent;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UnitController extends Controller
{
    public function index(Request $request): View
    {
        $state = in_array($request->query('etat'), ['libres', 'occupes'], true) ? $request->query('etat') : null;

        return view('units.index', [
            'state' => $state,
            'properties' => Property::orderBy('name')->get(),
            'units' => SortedTable::query(Unit::with(['property', 'currentLease.tenant'])
                ->when($state === 'libres', fn ($query) => $query->vacant())
                ->when($state === 'occupes', fn ($query) => $query->occupied())
                ->when($request->query('property_id'), fn ($query, $id) => $query->where('property_id', $id))
                ->orderBy('property_id')->orderBy('number'), 'logements', [
                    'property' => SortedTable::related('properties', 'name', 'id', 'units.property_id'),
                    'number' => 'number',
                    'type' => 'type',
                    'rent' => 'rent',
                ]),
        ]);
    }

    public function create(Request $request): View
    {
        return view('units.form', [
            'unit' => new Unit(['property_id' => $request->query('property_id'), 'type' => 'apartment']),
            'properties' => Property::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unit = Unit::create($this->validated($request));

        return redirect()->route('properties.show', $unit->property_id)->with('success', __('Logement n° :v1 ajouté.', ['v1' => $unit->number]));
    }

    public function show(Unit $unit): View
    {
        return view('units.show', [
            'unit' => $unit->load(['property', 'currentLease.tenant', 'currentStay']),
            'stays' => $unit->stays()->with('guest')->latest('check_in_at')->limit(10)->get(),
            'leases' => SortedTable::query($unit->leases()->with('tenant')->orderByDesc('start_date'), 'baux', [
                'start' => 'start_date',
                'end' => 'end_date',
                'rent' => 'rent',
            ]),
        ]);
    }

    public function edit(Unit $unit): View
    {
        return view('units.form', ['unit' => $unit, 'properties' => Property::orderBy('name')->get()]);
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $unit->update($this->validated($request, $unit));

        return redirect()->route('units.show', $unit)->with('success', __('Logement modifié. Le loyer d\'un bail en cours se change sur ce bail.'));
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        if ($unit->leases()->exists()) {
            return back()->with('error', __('Ce logement a déjà eu un locataire : il ne peut pas être supprimé.'));
        }

        $propertyId = $unit->property_id;
        $unit->delete();

        return redirect()->route('properties.show', $propertyId)->with('success', __('Logement supprimé.'));
    }

    // Le détail d'un logement : aussi saisi à la création d'un bien qui est un logement seul.
    public static function detailRules(): array
    {
        return [
            // Obligatoire, sauf pour un meublé loué à la nuit.
            'rent' => [new MonthlyRent, 'nullable', 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'floor' => ['nullable', 'string', 'max:30'],
            'surface' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'living_rooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'kitchens' => ['nullable', 'integer', 'min:0', 'max:50'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:50'],
            'furnished' => ['nullable', 'boolean'],
            'daily_rate' => [new MonthlyRent('daily_rate'), 'nullable', 'numeric', 'gt:0', 'max:999999999', 'decimal:0,3'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => [Rule::in(array_keys(config('units.amenities')))],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function detailAttributes(): array
    {
        return [
            'rent' => __('montant du loyer'),
            'daily_rate' => __('tarif journalier'),
            'floor' => __('étage'),
            'surface' => __('surface'),
            'bedrooms' => __('chambres'),
            'living_rooms' => __('salons'),
            'kitchens' => __('cuisines'),
            'bathrooms' => __('douches'),
        ];
    }

    // Une case décochée n'est pas envoyée par le navigateur : elle doit quand même effacer la valeur.
    public static function normalizeDetails(array $data, Request $request): array
    {
        $data['furnished'] = $request->boolean('furnished');
        // Le tarif journalier ne vaut que pour un logement meublé.
        $data['daily_rate'] = $data['furnished'] && ($data['daily_rate'] ?? '') !== '' ? $data['daily_rate'] : null;
        $data['amenities'] = array_values(array_unique($data['amenities'] ?? []));

        return self::settleRent($data, 'rent');
    }

    /**
     * Un meublé loué seulement à la nuit n'a pas de loyer par mois (la règle MonthlyRent l'a vérifié) : il est
     * enregistré à 0. Par sécurité, un logement sans loyer ni tarif journalier reste refusé.
     */
    public static function settleRent(array $data, string $errorKey): array
    {
        if ((float) ($data['rent'] ?? 0) > 0) {
            return $data;
        }

        if (! empty($data['furnished']) && (float) ($data['daily_rate'] ?? 0) > 0) {
            $data['rent'] = 0;

            return $data;
        }

        throw ValidationException::withMessages([$errorKey => __('Le loyer par mois est obligatoire, sauf pour un logement meublé loué à la nuit (renseignez alors son tarif journalier).')]);
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        $data = $request->validate([
            'property_id' => ['required', Rule::exists('properties', 'id')->where('organisation_id', $request->user()->organisation_id)],
            'number' => ['required', 'string', 'max:50', Rule::unique('units', 'number')->where('property_id', $request->input('property_id'))->ignore($unit)],
            'type' => ['required', Rule::in(Unit::TYPES)],
        ] + self::detailRules(), [
            'number.unique' => __('Ce numéro existe déjà dans ce bien.'),
        ], [
            'property_id' => __('bien'),
            'number' => __('numéro'),
        ] + self::detailAttributes());

        $property = Property::findOrFail($data['property_id']);

        if ($property->isSingle()) {
            if ($property->units()->when($unit, fn ($query) => $query->whereKeyNot($unit->id))->exists()) {
                throw ValidationException::withMessages(['property_id' => __('Ce bien est un logement seul et il a déjà sa fiche. Pour lui ajouter des logements, changez son type en « Ensemble de logements ».')]);
            }

            $data['type'] = $property->kind;
        }

        return self::normalizeDetails($data, $request);
    }
}
