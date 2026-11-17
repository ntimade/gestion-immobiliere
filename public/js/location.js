// Localisation de l'appareil, partagée par la carte et la fiche du bien.
// La première demande déclenche l'invitation du navigateur à autoriser l'accès.
window.locateUser = function (onSuccess, onError) {
    if (!window.isSecureContext) {
        onError(window.locationText.insecure);
        return;
    }
    if (!navigator.geolocation) {
        onError(window.locationText.unsupported);
        return;
    }

    var messages = {
        1: window.locationText.denied,
        2: window.locationText.unavailable,
        3: window.locationText.timeout
    };

    navigator.geolocation.getCurrentPosition(
        function (position) { onSuccess(position.coords); },
        function (error) { onError(messages[error.code] || messages[2]); },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }
    );
};

// Affiche ou déplace le point bleu « vous êtes ici » et sa zone de précision.
window.showUserPosition = function (map, coords, layer) {
    if (layer) {
        map.removeLayer(layer);
    }

    var center = [coords.latitude, coords.longitude];

    return L.layerGroup([
        L.circle(center, { radius: coords.accuracy, weight: 1, color: '#0ea5e9', fillOpacity: 0.12, interactive: false }),
        L.circleMarker(center, { radius: 7, weight: 3, color: '#fff', fillColor: '#0ea5e9', fillOpacity: 1 }).bindTooltip(window.locationText.here)
    ]).addTo(map);
};

window.setLocationStatus = function (element, message, isError) {
    element.textContent = message;
    element.classList.toggle('location-error', Boolean(isError));
};
