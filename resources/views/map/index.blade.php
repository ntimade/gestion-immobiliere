@extends('layouts.app')
@section('title', __('Carte'))

@push('head')<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">@endpush

@section('content')
    <div class="page-head"><h1>{{ __('Carte des biens') }}</h1></div>

    <div class="card">
        <div class="location-bar">
            <p id="location-status" class="small">{{ __('Touchez « Ma position » pour vous situer sur la carte.') }}</p>
            <button type="button" class="btn ghost small" id="my-position">{{ __('Ma position') }}</button>
        </div>
        <div id="map"></div>
        <div class="map-legend small">
            <span><i style="background: #059669"></i> {{ __('Tout est loué') }}</span>
            <span><i style="background: #d97706"></i> {{ __('Logements libres') }}</span>
            <span><i style="background: #94a3b8"></i> {{ __('Aucun locataire') }}</span>
            <span><i style="background: #0ea5e9"></i> {{ __('Vous') }}</span>
        </div>
        <p class="soft small">{{ __(':v1 bien(s) sur la carte.', ['v1' => count($points)]) }}
            @if ($withoutPosition > 0){{ __(':v1 sans position : renseignez-la dans la fiche du bien.', ['v1' => $withoutPosition]) }}@endif
        </p>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset_v('js/location.js') }}"></script>
    <script>
        (function () {
            var points = @json($points);
            var colors = { full: '#059669', partial: '#d97706', empty: '#94a3b8' };
            var map = L.map('map');
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(map);

            function line(parent, text, tag) {
                var element = document.createElement(tag || 'div');
                element.textContent = text;
                parent.appendChild(element);
                return element;
            }

            function popupFor(point) {
                var popup = document.createElement('div');
                popup.className = 'meter-popup';
                line(popup, point.name, 'a').href = point.url;
                if (point.place) {
                    line(popup, point.place).className = 'soft';
                }
                line(popup, {{ Js::from(__('Logements occupés')) }} + ' : ' + point.occupied + ' / ' + point.units);
                line(popup, {{ Js::from(__('Revenu mensuel actuel')) }} + ' : ' + point.income);
                line(popup, {{ Js::from(__('Revenu estimé si tout est loué')) }} + ' : ' + point.estimated);
                return popup;
            }

            var markers = points.map(function (point) {
                return L.circleMarker([point.lat, point.lng], {
                    radius: 11, weight: 2, color: '#fff', fillOpacity: 1, fillColor: colors[point.status]
                }).bindPopup(popupFor(point)).addTo(map);
            });

            if (markers.length) {
                map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2), { maxZoom: 16 });
            } else {
                map.setView([5, 15], 3);
            }

            var status = document.getElementById('location-status');
            var userLayer = null;

            document.getElementById('my-position').addEventListener('click', function () {
                locateUser(function (coords) {
                    userLayer = showUserPosition(map, coords, userLayer);
                    map.setView([coords.latitude, coords.longitude], 16);
                    setLocationStatus(status, {{ Js::from(__('Votre position est affichée en bleu, précision d\'environ ')) }} + Math.round(coords.accuracy) + ' m.');
                }, function (message) {
                    setLocationStatus(status, message, true);
                });
            });
        })();
    </script>
@endpush
