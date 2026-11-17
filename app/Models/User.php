<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    protected $fillable = ['organisation_id', 'name', 'email', 'password'];

    protected $attributes = ['role' => self::ADMIN];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function roleLabel(): string
    {
        return self::roleName((string) $this->role);
    }

    public static function roleName(string $role): string
    {
        return $role === self::MANAGER ? __('Gestionnaire') : __('Administrateur');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function securityAnswers(): HasMany
    {
        return $this->hasMany(SecurityAnswer::class);
    }
}
