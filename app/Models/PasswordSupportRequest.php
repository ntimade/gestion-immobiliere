<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordSupportRequest extends Model
{
    public const PENDING = 'pending';

    public const RESET = 'reset';

    public const CLOSED = 'closed';

    public const SELF_SERVICE = 'self_service';

    protected $fillable = ['user_id', 'organisation_code', 'login', 'contact', 'message', 'status', 'handled_by', 'handled_at', 'note'];

    protected $casts = ['handled_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(SupportAdmin::class, 'handled_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::RESET => __('Mot de passe réinitialisé'),
            self::CLOSED => __('Clôturée'),
            self::SELF_SERVICE => __('Résolue par le client'),
            default => __('En attente'),
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::RESET, self::SELF_SERVICE => 'ok',
            self::CLOSED => 'off',
            default => 'partial',
        };
    }
}
