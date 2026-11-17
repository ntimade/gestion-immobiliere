{{-- Une ligne de logement dans le formulaire d'un ensemble. $existing : le logement déjà enregistré, s'il y en a un. --}}
@php
    $prefix = "units[$index]";
    $fieldId = fn (string $field) => "units-$index-$field";
    $selected = $row['amenities'] ?? [];
@endphp
<div class="unit-row subpanel" data-unit-row>
    <div class="row-between">
        <strong class="unit-row-title">{{ __('Logement') }}</strong>
        <div class="actions">
            @if ($existing)
                @if ($existing->currentLease)
                    <span class="badge ok">{{ __('Occupé') }}</span>
                    <span class="small">{{ $existing->currentLease->tenant->name }}</span>
                @else
                    <span class="badge off">{{ __('Libre') }}</span>
                @endif
            @else
                <span class="badge partial">{{ __('Nouveau') }}</span>
            @endif
            <button type="button" class="btn ghost small" data-duplicate-unit>{{ __('Dupliquer') }}</button>
            @unless ($existing && $existing->leases_count > 0)
                <button type="button" class="btn danger small" data-remove-unit @if ($existing) data-existing="1" @endif>{{ __('Retirer') }}</button>
            @endunless
        </div>
    </div>

    @if ($existing)
        <input type="hidden" name="{{ $prefix }}[id]" value="{{ $existing->id }}" data-keep>
        <input type="hidden" name="{{ $prefix }}[delete]" value="" data-delete-flag data-keep>
    @endif

    <div class="unit-grid">
        <div class="field">
            <label for="{{ $fieldId('type') }}">{{ __('Type') }} <span class="req">*</span></label>
            <select id="{{ $fieldId('type') }}" name="{{ $prefix }}[type]" required>
                @foreach (\App\Models\Unit::TYPES as $type)
                    <option value="{{ $type }}" @selected(($row['type'] ?? 'apartment') === $type)>{{ \App\Models\Unit::typeName($type) }}</option>
                @endforeach
            </select>
            @error("units.$index.type")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="{{ $fieldId('number') }}">{{ __('Numéro') }} <span class="req">*</span></label>
            <input id="{{ $fieldId('number') }}" type="text" name="{{ $prefix }}[number]" value="{{ $row['number'] ?? '' }}" placeholder="{{ __('Ex. 12 ou A3') }}" maxlength="50" required data-clear-on-copy>
            @error("units.$index.number")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="{{ $fieldId('rent') }}">{{ __('Tarif par mois') }} <span class="req">*</span></label>
            <input id="{{ $fieldId('rent') }}" type="number" name="{{ $prefix }}[rent]" value="{{ isset($row['rent']) && $row['rent'] !== '' ? (float) $row['rent'] : '' }}" step="any" min="0" required data-rent>
            @error("units.$index.rent")<div class="error">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="unit-grid">
        @foreach (['bedrooms' => __('Chambres'), 'living_rooms' => __('Salons'), 'kitchens' => __('Cuisines'), 'bathrooms' => __('Douches')] as $field => $label)
            <div class="field">
                <label for="{{ $fieldId($field) }}">{{ $label }}</label>
                <input id="{{ $fieldId($field) }}" type="number" name="{{ $prefix }}[{{ $field }}]" value="{{ $row[$field] ?? '' }}" min="0" max="50" step="1">
                @error("units.$index.$field")<div class="error">{{ $message }}</div>@enderror
            </div>
        @endforeach
        <div class="field">
            <label for="{{ $fieldId('surface') }}">{{ __('Surface (m²)') }}</label>
            <input id="{{ $fieldId('surface') }}" type="number" name="{{ $prefix }}[surface]" value="{{ isset($row['surface']) && $row['surface'] !== '' ? (float) $row['surface'] : '' }}" step="any" min="0">
            @error("units.$index.surface")<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="{{ $fieldId('floor') }}">{{ __('Étage') }}</label>
            <input id="{{ $fieldId('floor') }}" type="text" name="{{ $prefix }}[floor]" value="{{ $row['floor'] ?? '' }}" maxlength="30">
            @error("units.$index.floor")<div class="error">{{ $message }}</div>@enderror
        </div>
    </div>

    {{-- Tout type de logement peut être meublé : la case reste visible. --}}
    <div class="field">
        <label class="check"><input type="checkbox" name="{{ $prefix }}[furnished]" value="1" @checked(! empty($row['furnished'])) data-furnished> {{ __('Meublé') }}</label>
    </div>
    <div class="field">
        <label for="{{ $fieldId('daily_rate') }}">{{ __('Tarif journalier (24 h)') }}</label>
        <input id="{{ $fieldId('daily_rate') }}" type="number" name="{{ $prefix }}[daily_rate]" value="{{ isset($row['daily_rate']) && $row['daily_rate'] !== '' && $row['daily_rate'] !== null ? (float) $row['daily_rate'] : '' }}" step="any" min="0" data-daily-rate>
        <div class="help">{{ __('Pour louer ce logement meublé à la nuit (une nuit minimum). Avec un tarif journalier, le loyer par mois devient facultatif. Laissez vide pour une location au mois seulement.') }}</div>
        @error("units.$index.daily_rate")<div class="error">{{ $message }}</div>@enderror
    </div>

    <details class="unit-more" @if (! empty($selected) || ! empty($row['notes'])) open @endif>
        <summary>{{ __('Équipements et observation') }}</summary>
        <div class="field">
            <div class="choices">
                @foreach (config('units.amenities') as $key => $label)
                    <label class="choice"><input type="checkbox" name="{{ $prefix }}[amenities][]" value="{{ $key }}" @checked(in_array($key, $selected, true))> {{ __($label) }}</label>
                @endforeach
            </div>
        </div>
        <div class="field">
            <label for="{{ $fieldId('notes') }}">{{ __('Observation') }}</label>
            <input id="{{ $fieldId('notes') }}" type="text" name="{{ $prefix }}[notes]" value="{{ $row['notes'] ?? '' }}" maxlength="255">
        </div>
    </details>
</div>
