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

    public function atiende(): bool
    {
        return ! $this->cerrado && $this->abre !== null && $this->cierra !== null;
    }

    /**
     * Horario que termina al día siguiente, por ejemplo de 18:00 a 01:00.
     */
    public function cruzaMedianoche(): bool
    {
        return $this->atiende() && $this->horaDe($this->cierra) <= $this->horaDe($this->abre);
    }

    /**
     * @param  string  $hora  en formato HH:MM
     */
    public function cubre(string $hora): bool
    {
        if (! $this->atiende()) {
            return false;
        }

        return $this->cruzaMedianoche()
            ? $hora >= $this->horaDe($this->abre)
            : $hora >= $this->horaDe($this->abre) && $hora < $this->horaDe($this->cierra);
    }

    /**
     * La madrugada del día siguiente, en un horario que cruza medianoche.
     *
     * @param  string  $hora  en formato HH:MM
     */
    public function cubreMadrugadaSiguiente(string $hora): bool
    {
        return $this->cruzaMedianoche() && $hora < $this->horaDe($this->cierra);
    }

    public function rangoTexto(): string
    {
        return $this->horaDe($this->abre).' a '.$this->horaDe($this->cierra);
    }

    private function horaDe(string $hora): string
    {
        return substr($hora, 0, 5);
    }
}
