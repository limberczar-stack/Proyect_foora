<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class Impresora extends Model
{
    use BelongsToRestaurante;

    protected $table = 'impresoras';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'tipo',
        'conexion',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }
}
