<script>
    window.tableText = {{ Js::from([
        'sortBy' => __('Trier par '),
        'usual' => __('Ordre habituel'),
        'label' => __('Trier cette liste'),
        'number' => ['desc' => __('décroissant'), 'asc' => __('croissant')],
        'date' => ['desc' => __('récent d\'abord'), 'asc' => __('ancien d\'abord')],
    ]) }};
</script>
