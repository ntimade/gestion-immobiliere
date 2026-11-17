<?php

use Illuminate\Support\Carbon;

if (! function_exists('asset_v')) {
    // Adresse d'un fichier public, renouvelée à chaque modification pour éviter l'ancienne version en cache.
    function asset_v(string $path): string
    {
        return asset($path).'?v='.@filemtime(public_path($path));
    }
}

if (! function_exists('money')) {
    function money($value): string
    {
        $organisation = auth()->user()->organisation;

        return number_format((float) $value, $organisation->currencyDecimals(), ',', ' ').' '.$organisation->currency;
    }
}

if (! function_exists('qty')) {
    // Index et volumes : jusqu'à 3 décimales, sans zéros inutiles.
    function qty($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', ' '), '0'), ',');
    }
}

if (! function_exists('ui_theme')) {
    // Mode d'affichage choisi sur cet appareil ; clair par défaut.
    function ui_theme(): string
    {
        return request()->cookie('theme') === 'dark' ? 'dark' : 'light';
    }
}

if (! function_exists('period_label')) {
    function period_label(string $period): string
    {
        return Carbon::createFromFormat('!Y-m', $period)->locale(app()->getLocale())->translatedFormat('F Y');
    }
}

if (! function_exists('of_period')) {
    // « de novembre 2026 », mais « d'octobre 2026 ».
    function of_period(string $period): string
    {
        $label = period_label($period);

        if (app()->getLocale() === 'en') {
            return 'for '.$label;
        }

        return (preg_match('/^[aâàeéèêioôu]/iu', $label) ? "d'" : 'de ').$label;
    }
}
