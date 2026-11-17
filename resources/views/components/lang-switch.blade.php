@php($target = app()->getLocale() === 'en' ? 'fr' : 'en')

<a class="lang-switch" href="{{ route('locale.switch', $target) }}" hreflang="{{ $target }}" lang="{{ $target }}"
   title="{{ $target === 'en' ? 'English' : 'Français' }}" aria-label="{{ $target === 'en' ? 'Switch to English' : 'Passer en français' }}">
    <x-icon name="languages" />
    <span>{{ strtoupper($target) }}</span>
</a>
