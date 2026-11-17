<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityAnswer extends Model
{
    protected $fillable = ['question', 'answer'];

    protected $hidden = ['answer'];
}
