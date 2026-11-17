<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// Format international sans espaces (+237699000000), celui qu'attendent les liens WhatsApp.
class PhoneNumber
{
    private const MIN_DIGITS = 8;

    private const MAX_DIGITS = 15;

    private const COMPLETE_DIGITS = 11;

    public static function isValid(string $raw): bool
    {
        if (! preg_match('/^\+?[\d\s().\-]+$/u', trim($raw))) {
            return false;
        }

        $length = strlen(ltrim(preg_replace('/\D/', '', $raw), '0'));

        return $length >= self::MIN_DIGITS - 1 && $length <= self::MAX_DIGITS;
    }

    public static function isInternational(?string $phone): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{'.(self::MIN_DIGITS - 1).','.(self::MAX_DIGITS - 1).'}$/', (string) $phone);
    }

    // Null quand le numéro est illisible, ou sans indicatif alors que le pays n'est pas connu.
    public static function normalize(string $raw, ?string $country): ?string
    {
        if (! self::isValid($raw)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        if (str_starts_with(trim($raw), '+')) {
            $number = '+'.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $number = '+'.substr($digits, 2);
        } else {
            $entry = config('phone_countries.'.$country);

            // Indicatif déjà saisi sans le + : un numéro national seul ne serait pas aussi long.
            if (($entry && str_starts_with($digits, $entry[1]) && strlen($digits) >= strlen($entry[1]) + self::MIN_DIGITS)
                || self::startsWithAKnownCode($digits)) {
                $number = '+'.$digits;
            } elseif (! $entry) {
                return null;
            } else {
                [, $code, $keepsLeadingZero] = $entry;
                $number = '+'.$code.($keepsLeadingZero ? $digits : ltrim($digits, '0'));
            }
        }

        return self::isInternational($number) ? $number : null;
    }

    // Numéro complet écrit sans le + : indicatif d'un pays connu, et trop long pour un numéro national.
    private static function startsWithAKnownCode(string $digits): bool
    {
        return strlen($digits) >= self::COMPLETE_DIGITS
            && collect(config('phone_countries'))->contains(fn ($entry) => str_starts_with($digits, $entry[1]));
    }

    // Numéro à enregistrer : sans pays connu, il reste tel que saisi jusqu'au choix du pays.
    public static function forStorage(?string $raw, ?string $country): ?string
    {
        $raw = trim((string) $raw);

        return $raw === '' ? null : (self::normalize($raw, $country) ?? $raw);
    }

    // Met au format les numéros d'un compte. Ceux qui restent illisibles ne sont pas modifiés.
    public static function normalizeOrganisation(int $organisationId, ?string $country): array
    {
        $updated = 0;
        $invalid = 0;

        $tenants = DB::table('tenants')->where('organisation_id', $organisationId)
            ->whereNotNull('phone')->where('phone', '<>', '')->get(['id', 'phone']);

        foreach ($tenants as $tenant) {
            $number = self::normalize($tenant->phone, $country);

            if ($number === null) {
                $invalid++;
            } elseif ($number !== $tenant->phone) {
                DB::table('tenants')->where('id', $tenant->id)->update(['phone' => $number]);
                $updated++;
            }
        }

        return ['updated' => $updated, 'invalid' => $invalid];
    }

    // Pays déduit d'un numéro complet, l'indicatif le plus long d'abord.
    public static function guessCountry(string $phone): ?string
    {
        if (! str_starts_with(trim($phone), '+')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        return collect(config('phone_countries'))
            ->sortByDesc(fn ($entry) => strlen($entry[1]))
            ->search(fn ($entry) => str_starts_with($digits, $entry[1])) ?: null;
    }
}
