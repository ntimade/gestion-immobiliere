@props(['table', 'key', 'kind' => 'text'])

@php
    // Un clic trie, le deuxième inverse, le troisième revient à l'ordre habituel.
    $first = $kind === 'text' ? 'asc' : 'desc';
    $second = $first === 'asc' ? 'desc' : 'asc';
    $current = $table->direction($key);
    $next = match ($current) {
        $first => $table->url($key, $second),
        $second => $table->url(),
        default => $table->url($key, $first),
    };
@endphp

<th {{ $attributes }} aria-sort="{{ ['asc' => 'ascending', 'desc' => 'descending'][$current] ?? 'none' }}">
    <a class="sort-link" href="{{ $next }}" data-kind="{{ $kind }}" data-asc="{{ $table->url($key, 'asc') }}" data-desc="{{ $table->url($key, 'desc') }}" data-reset="{{ $table->url() }}" data-current="{{ $current }}">{{ $slot }}</a>
</th>
