<div class="form-grid">
    <x-field name="surface" :label="__('Surface (m²)')" type="number" step="any" min="0" :value="$unit->surface === null ? null : (float) $unit->surface" />
    <x-field name="floor" :label="__('Étage')" :value="$unit->floor" :placeholder="__('Ex. Rez-de-chaussée, 2e')" />
</div>
<div class="form-grid">
    <x-field name="bedrooms" :label="__('Chambres')" type="number" min="0" max="50" step="1" :value="$unit->bedrooms" />
    <x-field name="living_rooms" :label="__('Salons')" type="number" min="0" max="50" step="1" :value="$unit->living_rooms" />
</div>
<div class="form-grid">
    <x-field name="kitchens" :label="__('Cuisines')" type="number" min="0" max="50" step="1" :value="$unit->kitchens" />
    <x-field name="bathrooms" :label="__('Douches')" type="number" min="0" max="50" step="1" :value="$unit->bathrooms" />
</div>
<div class="field">
    <label class="check"><input type="checkbox" name="furnished" value="1" @checked(old('furnished', $unit->furnished)) data-furnished> {{ __('Meublé') }}</label>
</div>
<x-field name="daily_rate" data-daily-rate :label="__('Tarif journalier (24 h)')" type="number" step="any" min="0" :value="$unit->daily_rate === null ? null : (float) $unit->daily_rate" :help="__('Pour louer ce logement meublé à la nuit (une nuit minimum). Avec un tarif journalier, le loyer par mois devient facultatif. Laissez vide pour une location au mois seulement.')" />
<div class="field">
    <label>{{ __('Équipements') }}</label>
    <div class="choices">
        @php($selectedAmenities = old('amenities', $unit->amenities ?? []))
        @foreach (config('units.amenities') as $key => $label)
            <label class="choice"><input type="checkbox" name="amenities[]" value="{{ $key }}" @checked(in_array($key, $selectedAmenities, true))> {{ __($label) }}</label>
        @endforeach
    </div>
    @error('amenities')<div class="error">{{ $message }}</div>@enderror
</div>
<x-field name="notes" :label="__('Observation')" :value="$unit->notes" />
