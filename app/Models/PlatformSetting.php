<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Réglages de la plateforme, communs à toutes les organisations.
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key): ?string
    {
        return self::find($key)?->value;
    }

    public static function put(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
