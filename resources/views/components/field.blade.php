@props(['name', 'label', 'type' => 'text', 'value' => null, 'required' => false, 'help' => null, 'errorBag' => 'default'])

<div class="field">
    <label for="{{ $name }}">{{ $label }}@if ($required) <span class="req">*</span>@endif</label>
    @if ($slot->isNotEmpty())
        {{ $slot }}
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
               @unless (in_array($type, ['password'])) value="{{ old($name, $value) }}" @endunless
               @required($required) {{ $attributes }}>
    @endif
    @if ($help)<div class="help">{{ $help }}</div>@endif
    @error($name, $errorBag)<div class="error">{{ $message }}</div>@enderror
</div>
