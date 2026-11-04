<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurante;
use Illuminate\Database\Eloquent\Model;

class HorarioAtencion extends Model
{
    use BelongsToRestaurante;

    protected $table = 'horarios_atencion';

    protected $fillable = [
        'restaurante_id',
        'dia_semana',
        'abre',
        'cierra',
        'cerrado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dia_semana' => 'integer',
            'cerrado' => 'boolean',
        ];
    }
}
