<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Un client de passage, qui loue un logement meublé à la nuit. Distinct des locataires au mois.
class Guest extends Model
{
    use BelongsToOrganisation;

    protected $fillable = ['name', 'gender', 'phone', 'id_number'];

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function title(): string
    {
        return match ($this->gender) {
            'M' => __('Monsieur'),
            'F' => __('Madame'),
            default => __('Monsieur/Madame'),
        };
    }

    public function smsName(): string
    {
        $title = match ($this->gender) {
            'M' => __('M.'),
            'F' => __('Mme'),
            default => __('M./Mme'),
        };

        return $title.' '.$this->name;
    }
}
