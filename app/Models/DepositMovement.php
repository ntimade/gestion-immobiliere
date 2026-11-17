<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Une ligne de l'historique de la caution. Montant positif : la caution augmente ; négatif : elle diminue.
class DepositMovement extends Model
{
    use BelongsToOrganisation;

    public const PAID = 'paid';

    public const ADJUSTED = 'adjusted';

    public const RETAINED = 'retained';

    public const REFUNDED = 'refunded';

    public const EXIT_CANCELLED = 'exit_cancelled';

    protected $fillable = ['organisation_id', 'lease_id', 'user_id', 'type', 'amount', 'happened_on', 'note'];

    protected $casts = ['happened_on' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return match ($this->type) {
            self::PAID => __('Caution versée'),
            self::RETAINED => __('Retenue sur la caution'),
            self::REFUNDED => __('Caution rendue'),
            self::EXIT_CANCELLED => __('Sortie annulée : caution rétablie'),
            default => __('Correction de la caution'),
        };
    }
}
