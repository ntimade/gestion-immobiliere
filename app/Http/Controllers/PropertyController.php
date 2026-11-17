<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Unit;
use App\Services\PropertyFigures;
use App\Support\SortedTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PropertyController extends Controller
{
    public function index(PropertyFigures $figures): View
    {
        return view('properties.index', [
            'totals' => $figures->totals(),
            'properties' => SortedTable::query(Property::query()
                ->withCount([
                    'units',
                    'units as occupied_count' => fn ($unit) => $unit->occupied(),
                    'units as apartments_count' => fn ($unit) => $unit->where('type', 'apartment'),
                    'units as rooms_count' => fn ($unit) => $unit->where('type', 'room'),
                ])
                ->withIncome()
                ->orderBy('name'), 'biens', [
                    'name' => 'name',
                    'kind' => 'kind',
                    'city' => 'city',
                    'apartments' => 'apartments_count',
                    'rooms' => 'rooms_count',
                    'units' => 'units_count',
                    'occupied' => 'occupied_count',
                    'current' => 'current_income',
                    'estimated' => 'estimated_income',
                ]),
        ]);
    }

    public function create(): View
    {
        return view('properties.form', ['property' => new Property]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if ($data['kind'] === Property::MIXED) {
            [$rows] = $this->validatedRows($request);

            // Tout est créé ensemble : un logement refusé n'ajoute pas le bien.
            $property = DB::transaction(function () use ($data, $rows) {
                $property = Property::create($data);
                $this->saveRows($property, $rows, []);

                return $property;
            });

            return redirect()->route('properties.show', $property)->with('success', $rows
                ? __('Bien ajouté avec :v1 logement(s).', ['v1' => count($rows)])
                : __('Bien ajouté. Ajoutez maintenant ses appartements ou ses chambres.'));
        }

        // Un logement seul : sa fiche est créée avec le bien, ou rien n'est créé.
        $details = UnitController::normalizeDetails(
            $request->validate(UnitController::detailRules(), [], UnitController::detailAttributes()),
            $request,
        );

        $property = DB::transaction(function () use ($data, $details) {
            $property = Property::create($data);
            $property->units()->create(['number' => '1', 'type' => $data['kind']] + $details);

            return $property;
        });

        return redirect()->route('properties.show', $property)->with('success', __('Bien ajouté. Vous pouvez enregistrer l\'entrée de son locataire.'));
    }

    public function show(Property $property, PropertyFigures $figures): View
    {
        return view('properties.show', [
            'property' => $property,
            'figures' => $figures->forProperty($property),
            'units' => SortedTable::query($property->units()->with('currentLease.tenant')->orderBy('number'), 'logements', [
                'number' => 'number',
                'type' => 'type',
                'rent' => 'rent',
            ]),
        ]);
    }

    public function edit(Property $property): View
    {
        $property->load(['units' => fn ($unit) => $unit->with('currentLease.tenant')->withCount('leases')->orderBy('number')]);

        return view('properties.form', compact('property'));
    }

    public function update(Request $request, Property $property): RedirectResponse
    {
        $data = $this->validated($request, $property);

        if ($data['kind'] !== Property::MIXED && $property->units()->count() > 1) {
            throw ValidationException::withMessages(['kind' => __('Ce bien a plusieurs logements : il doit rester un ensemble de logements.')]);
        }

        [$rows, $deleted] = $data['kind'] === Property::MIXED ? $this->validatedRows($request, $property) : [[], []];

        DB::transaction(function () use ($property, $data, $rows, $deleted) {
            $property->update($data);

            if ($property->isSingle()) {
                $property->units()->update(['type' => $property->kind]);

                return;
            }

            $this->saveRows($property, $rows, $deleted);
        });

        return redirect()->route('properties.show', $property)->with('success', __('Bien modifié.'));
    }

    public function destroy(Property $property): RedirectResponse
    {
        if ($property->units()->exists()) {
            return back()->with('error', __('Ce bien a encore des logements : il ne peut pas être supprimé.'));
        }

        $property->delete();

        return redirect()->route('properties.index')->with('success', __('Bien supprimé.'));
    }

    /**
     * Les lignes de logements d'un ensemble, chacune avec ses détails et son tarif. Une ligne
     * existante marquée à supprimer n'est pas validée : seul son identifiant est gardé.
     *
     * @return array{0: array, 1: array} les lignes à enregistrer et les identifiants à supprimer
     */
    private function validatedRows(Request $request, ?Property $property = null): array
    {
        $submitted = collect($request->input('units', []));
        $deleted = $submitted->filter(fn ($row) => ! empty($row['delete']) && ! empty($row['id']))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $request->merge(['units' => $submitted->reject(fn ($row) => ! empty($row['delete']))->all()]);

        $rules = ['units' => ['nullable', 'array']];
        foreach (UnitController::detailRules() as $field => $fieldRules) {
            $rules['units.*.'.$field] = $fieldRules;
        }

        $data = $request->validate($rules + [
            'units.*.id' => ['nullable', 'integer', Rule::exists('units', 'id')->where('property_id', $property?->id ?? 0)],
            'units.*.type' => ['required', Rule::in(Unit::TYPES)],
            'units.*.number' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
        ], [
            'units.*.number.distinct' => __('Ce numéro est saisi deux fois.'),
        ], collect(UnitController::detailAttributes())->mapWithKeys(fn ($label, $field) => ['units.*.'.$field => $label])->all() + [
            'units.*.number' => __('numéro'),
            'units.*.type' => __('type'),
        ]);

        $rows = collect($data['units'] ?? [])->map(function (array $row, $index) {
            $row['furnished'] = filter_var($row['furnished'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $row['daily_rate'] = $row['furnished'] && ($row['daily_rate'] ?? '') !== '' ? $row['daily_rate'] : null;
            $row['amenities'] = array_values(array_unique($row['amenities'] ?? []));

            return UnitController::settleRent($row, "units.$index.rent");
        })->values()->all();

        return [$rows, $deleted];
    }

    private function saveRows(Property $property, array $rows, array $deleted): void
    {
        $units = $property->units()->lockForUpdate()->get()->keyBy('id');

        foreach ($deleted as $id) {
            $unit = $units[$id] ?? null;
            abort_unless($unit, 404);

            if ($unit->leases()->exists()) {
                throw ValidationException::withMessages(['units' => __('Le logement n° :v1 a déjà eu un locataire : il ne peut pas être supprimé.', ['v1' => $unit->number])]);
            }

            $unit->delete();
            $units->forget($id);
        }

        $submittedIds = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id);
        $kept = $units->except($submittedIds->all())->pluck('number')->map(fn ($number) => mb_strtolower($number));
        $collision = collect($rows)->first(fn (array $row) => $kept->contains(mb_strtolower($row['number'])));
        if ($collision) {
            throw ValidationException::withMessages(['units' => __('Le numéro :v1 existe déjà dans ce bien.', ['v1' => $collision['number']])]);
        }

        // Numéros provisoires d'abord : deux logements peuvent échanger leur numéro.
        foreach ($rows as $row) {
            if (! empty($row['id']) && $units[$row['id']]->number !== $row['number']) {
                $units[$row['id']]->update(['number' => '#'.$row['id']]);
            }
        }

        foreach ($rows as $row) {
            $values = collect($row)->except('id')->all();

            empty($row['id']) ? $property->units()->create($values) : $units[$row['id']]->update($values);
        }
    }

    private function validated(Request $request, ?Property $property = null): array
    {
        $request->mergeIfMissing(['kind' => $property?->kind ?? Property::MIXED]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('properties', 'name')
                ->where('organisation_id', $request->user()->organisation_id)->ignore($property)],
            'kind' => ['required', Rule::in(Property::kinds())],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhood' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ], [], ['name' => __('nom du bien'), 'city' => __('ville'), 'neighbourhood' => __('quartier')]);
    }
}
