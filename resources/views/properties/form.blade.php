@extends('layouts.app')
@section('title', $property->exists ? __('Modifier le bien') : __('Nouveau bien'))

@push('head')<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">@endpush

@section('content')
    <div class="page-head"><h1>{{ $property->exists ? __('Modifier le bien') : __('Nouveau bien') }}</h1></div>
    <div class="card narrow">
        <form method="POST" action="{{ $property->exists ? route('properties.update', $property) : route('properties.store') }}">
            @csrf
            @if ($property->exists) @method('PUT') @endif
            <x-field name="name" :label="__('Nom du bien')" :value="$property->name" :required="true" :placeholder="__('Ex. Résidence Les Palmiers')" autofocus />
            <x-field name="kind" :label="__('Type de bien')" :required="true" :help="__('Choisissez « Ensemble de logements » si le bien réunit plusieurs appartements, chambres ou studios.')">
                <select id="kind" name="kind" required>
                    @foreach (\App\Models\Property::kinds() as $kind)
                        <option value="{{ $kind }}" @selected(old('kind', $property->kind) === $kind)>{{ \App\Models\Property::kindName($kind) }}</option>
                    @endforeach
                </select>
            </x-field>

            @php
                $savedUnits = $property->exists ? $property->units->keyBy('id') : collect();
                $unitRows = old('units', $property->exists
                    ? $property->units->mapWithKeys(fn ($unit, $position) => [$position => $unit->only(['type', 'number', 'rent', 'bedrooms', 'living_rooms', 'kitchens', 'bathrooms', 'surface', 'floor', 'furnished', 'daily_rate', 'amenities', 'notes']) + ['id' => $unit->id]])->all()
                    : [0 => []]);
                $nextUnitIndex = $unitRows ? max(array_keys($unitRows)) + 1 : 0;
            @endphp
            <div id="logements" class="unit-section">
                <div class="row-between">
                    <h2>{{ __('Logements de cet ensemble') }}</h2>
                    <span class="soft small" id="units-count"></span>
                </div>
                <p class="soft small">{{ __('Ajoutez chaque logement avec son type, ses pièces et son tarif : par exemple un studio, un appartement de 3 chambres et un appartement de 2 chambres. Le tarif sert de loyer à l\'entrée d\'un locataire.') }}</p>
                @error('units')<div class="flash error">{{ $message }}</div>@enderror

                <div id="unit-rows">
                    @foreach ($unitRows as $index => $row)
                        @include('units.row', ['index' => $index, 'row' => $row, 'existing' => $savedUnits[$row['id'] ?? 0] ?? null])
                    @endforeach
                </div>
                <p><button type="button" class="btn ghost small" id="add-unit">{{ __('Ajouter un logement') }}</button></p>
            </div>
            <template id="unit-template">@include('units.row', ['index' => '__INDEX__', 'row' => [], 'existing' => null])</template>

            @unless ($property->exists)
                <div id="single-unit" class="subpanel" data-rent-scope>
                    <p class="subpanel-title"><strong>{{ __('Détails du logement') }}</strong></p>
                    <x-field name="rent" data-rent :label="__('Montant du loyer par mois')" type="number" step="any" min="0" :required="true" />
                    @include('units.detail-fields', ['unit' => new \App\Models\Unit])
                </div>
            @endunless

            <h2>{{ __('Localisation') }}</h2>
            <div class="form-grid">
                <x-field name="city" :label="__('Ville')" :value="$property->city" />
                <x-field name="neighbourhood" :label="__('Quartier')" :value="$property->neighbourhood" />
            </div>
            <x-field name="address" :label="__('Adresse ou repère')" :value="$property->address" :help="__('Par exemple : rue, ou « derrière la pharmacie ».')" />

            <p class="subpanel-title"><strong>{{ __('Position sur la carte (facultatif)') }}</strong></p>
            <div class="location-bar">
                <p id="location-status" class="small">{{ __('Cliquez sur la carte à l\'emplacement du bien. Si vous êtes sur place, utilisez votre position.') }}</p>
                <button type="button" class="btn ghost small" id="use-location">{{ __('Placer à ma position') }}</button>
            </div>
            <div id="picker"></div>
            <div class="form-grid">
                <x-field name="latitude" :label="__('Latitude')" type="number" step="any" min="-90" max="90" :value="$property->latitude" />
                <x-field name="longitude" :label="__('Longitude')" type="number" step="any" min="-180" max="180" :value="$property->longitude" />
            </div>

            <h2>{{ __('Détails') }}</h2>
            <x-field name="description" :label="__('Description')" :value="$property->description" />

            <div class="form-actions">
                <button type="submit" class="btn">{{ __('Enregistrer') }}</button>
                <a class="btn ghost" href="{{ $property->exists ? route('properties.show', $property) : route('properties.index') }}">{{ __('Annuler') }}</a>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset_v('js/location.js') }}"></script>
    <script>
        (function () {
            var single = document.getElementById('single-unit');
            var kind = document.getElementById('kind');
            var section = document.getElementById('logements');
            var rows = document.getElementById('unit-rows');
            var template = document.getElementById('unit-template');
            var nextIndex = {{ $nextUnitIndex }};

            function visibleRows() {
                return Array.prototype.filter.call(rows.children, function (row) { return !row.hidden; });
            }

            // Champs désactivés quand ils sont masqués : ils ne bloquent pas l'envoi du formulaire.
            function setDisabled(container, disabled) {
                container.querySelectorAll('input, select').forEach(function (field) {
                    field.disabled = disabled && !(container.hidden && field.hasAttribute('data-keep') && kind.value === 'mixed');
                });
            }

            function refresh() {
                var isMixed = kind.value === 'mixed';
                if (single) {
                    single.hidden = isMixed;
                    setDisabled(single, isMixed);
                }
                section.hidden = !isMixed;
                Array.prototype.forEach.call(rows.children, function (row) {
                    setDisabled(row, !isMixed || row.hidden);
                });
                visibleRows().forEach(function (row, position) {
                    row.querySelector('.unit-row-title').textContent = {{ Js::from(__('Logement')) }} + ' ' + (position + 1);
                });
                document.getElementById('units-count').textContent = {{ Js::from(__('Total :')) }} + ' ' + visibleRows().length;
            }

            function newRow() {
                var holder = document.createElement('div');
                holder.innerHTML = template.innerHTML.replace(/__INDEX__/g, nextIndex++);
                var row = holder.firstElementChild;
                rows.appendChild(row);
                return row;
            }

            document.getElementById('add-unit').addEventListener('click', function () {
                var row = newRow();
                refresh();
                row.querySelector('input[type="text"]').focus();
            });

            rows.addEventListener('click', function (event) {
                var row = event.target.closest('[data-unit-row]');
                if (!row) { return; }

                if (event.target.closest('[data-remove-unit]')) {
                    var flag = row.querySelector('[data-delete-flag]');
                    if (flag) {
                        if (!confirm({{ Js::from(__('Retirer ce logement ? Il sera supprimé à l\'enregistrement.')) }})) { return; }
                        flag.value = '1';
                        row.hidden = true;
                    } else {
                        row.remove();
                    }
                    refresh();
                }

                if (event.target.closest('[data-duplicate-unit]')) {
                    var copy = newRow();
                    var sources = row.querySelectorAll('input:not([type="hidden"]), select');
                    var targets = copy.querySelectorAll('input:not([type="hidden"]), select');
                    sources.forEach(function (source, position) {
                        var target = targets[position];
                        if (source.type === 'checkbox') { target.checked = source.checked; }
                        else if (!source.hasAttribute('data-clear-on-copy')) { target.value = source.value; }
                    });
                    copy.querySelector('details').open = row.querySelector('details').open;
                    rows.insertBefore(copy, row.nextElementSibling);
                    refresh();
                    copy.querySelector('[data-clear-on-copy]').focus();
                }
            });

            kind.addEventListener('change', refresh);
            refresh();

            var status = document.getElementById('location-status');
            var lat = document.getElementById('latitude');
            var lng = document.getElementById('longitude');
            var map = L.map('picker');
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            var marker = null;
            function place(latitude, longitude) {
                if (marker) { marker.setLatLng([latitude, longitude]); }
                else { marker = L.marker([latitude, longitude]).addTo(map); }
            }

            var userLayer = null;

            if (lat.value && lng.value) {
                place(lat.value, lng.value);
                map.setView([lat.value, lng.value], 16);
            } else {
                map.setView([5, 15], 3);
            }

            // La localisation n'est demandée qu'à l'appui : on n'enregistre pas toujours un bien depuis place.
            document.getElementById('use-location').addEventListener('click', function () {
                if (lat.value && lng.value && !confirm({{ Js::from(__('Remplacer la position actuelle du bien ?')) }})) {
                    return;
                }
                setLocationStatus(status, {{ Js::from(__('Recherche de votre position…')) }});
                locateUser(function (coords) {
                    lat.value = coords.latitude.toFixed(7);
                    lng.value = coords.longitude.toFixed(7);
                    place(lat.value, lng.value);
                    userLayer = showUserPosition(map, coords, userLayer);
                    map.setView([lat.value, lng.value], 17);
                    var accuracy = Math.round(coords.accuracy);
                    setLocationStatus(status, {{ Js::from(__('Bien placé à votre position actuelle, précision d\'environ ')) }} + accuracy + ' m. '
                        + (accuracy > 100 ? {{ Js::from(__('C\'est approximatif : cliquez sur la carte pour corriger. ')) }} : '')
                        + {{ Js::from(__('Pensez à enregistrer.')) }});
                }, function (message) {
                    setLocationStatus(status, message, true);
                });
            });

            map.on('click', function (event) {
                lat.value = event.latlng.lat.toFixed(7);
                lng.value = event.latlng.lng.toFixed(7);
                place(lat.value, lng.value);
            });
            [lat, lng].forEach(function (input) {
                input.addEventListener('change', function () {
                    if (lat.value && lng.value) {
                        place(lat.value, lng.value);
                        map.setView([lat.value, lng.value], Math.max(map.getZoom(), 14));
                    }
                });
            });
        })();
    </script>
@endpush
