<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

// Compte du poste de support : hors de toute organisation, créé en ligne de commande.
class SupportAdmin extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
