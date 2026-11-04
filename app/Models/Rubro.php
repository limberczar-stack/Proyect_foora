<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rubro extends Model
{
    protected $table = 'rubros';

    protected $fillable = ['nombre'];

    /**
     * @return HasMany<Restaurante, $this>
     */
    public function restaurantes(): HasMany
    {
        return $this->hasMany(Restaurante::class);
    }
}
