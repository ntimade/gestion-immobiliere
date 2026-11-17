@php
    $next = ui_theme() === 'dark' ? 'light' : 'dark';
    $label = $next === 'light' ? __('Passer en mode clair') : __('Passer en mode sombre');
@endphp

<a class="lang-switch theme-switch" href="{{ route('theme.switch', $next) }}" title="{{ $label }}" aria-label="{{ $label }}">
    <x-icon :name="$next === 'light' ? 'sun' : 'moon'" />
</a>
