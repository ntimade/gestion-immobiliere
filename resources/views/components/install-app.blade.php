{{--
    Le bouton « Installer l'application », visible sur ordinateur, tablette et téléphone.
    Un clic installe ; si le navigateur n'est pas encore prêt, le bouton attend quelques secondes son accord.
    Déjà installée, l'application l'indique au lieu de proposer une installation impossible.
--}}
<div {{ $attributes->merge(['class' => 'install-app']) }}>
    <button type="button" class="btn ghost small block" data-install-app hidden>
        <x-icon name="download" /> <span data-install-label>{{ __('Installer l\'application') }}</span>
    </button>
    <p class="install-ios soft small" data-install-ios hidden>{{ __('Pour installer l\'application sur iPhone : touchez Partager, puis « Sur l\'écran d\'accueil ».') }}</p>
</div>
