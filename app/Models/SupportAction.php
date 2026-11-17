<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAction extends Model
{
    public const PASSWORD_RESET = 'password_reset';

    public const SUSPENDED = 'suspended';

    public const REACTIVATED = 'reactivated';

    public const SUBSCRIPTION_EXTENDED = 'subscription_extended';

    public const SUBSCRIPTION_REJECTED = 'subscription_rejected';

    protected $fillable = ['support_admin_id', 'organisation_id', 'user_id', 'action', 'note'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(SupportAdmin::class, 'support_admin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return match ($this->action) {
            self::PASSWORD_RESET => __('Mot de passe réinitialisé'),
            self::SUSPENDED => __('Compte suspendu'),
            self::SUBSCRIPTION_EXTENDED => __('Abonnement prolongé'),
            self::SUBSCRIPTION_REJECTED => __('Paiement refusé'),
            default => __('Compte réactivé'),
        };
    }
}
