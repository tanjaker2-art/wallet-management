<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance'];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

