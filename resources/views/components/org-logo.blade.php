@php
    $organisation = auth()->user()->organisation;
@endphp

@if ($organisation->logo_path)
    <span class="brand-logo has-image"><img src="{{ route('settings.logo', ['v' => $organisation->updated_at?->timestamp]) }}" alt=""></span>
@else
    <span class="brand-logo"><x-icon name="building" /></span>
@endif
