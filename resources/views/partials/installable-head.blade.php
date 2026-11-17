{{-- Application installable sur l'écran d'accueil (téléphone et ordinateur). --}}
<link rel="manifest" href="{{ asset_v('manifest.json') }}">
<meta name="theme-color" content="#2c1e1a">
<link rel="apple-touch-icon" href="{{ asset_v('images/app/apple-touch-icon.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Immobilier">
{{-- Les textes du bouton « Installer l'application », dans la langue de la page. --}}
<script>window.installLabels = {!! json_encode(["install" => __("Installer l'application"), "installed" => __("Application installée ✓"), "preparing" => __("Préparation de l'installation…"), "retry" => __("Réessayez dans un instant")], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};</script>
{{-- Garde l'accord d'installation de Chrome même s'il arrive avant le chargement des autres scripts (réseau lent). --}}
<script>window.earlyInstallPrompt = null; window.addEventListener("beforeinstallprompt", function (event) { event.preventDefault(); window.earlyInstallPrompt = event; });</script>
