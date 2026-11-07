<?php

namespace App\Rules;

use App\Support\SlugsReservados;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Comprueba que un slug se pueda usar como dirección pública: formato válido y no reservado.
 * Que no lo use otro restaurante se valida aparte, con Rule::unique.
 */
class SlugDisponible implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/\A'.SlugsReservados::FORMATO.'\z/', $value) !== 1) {
            $fail('La dirección solo admite minúsculas, números y guiones, por ejemplo el-buen-sabor.');

            return;
        }

        if (strlen($value) > SlugsReservados::LONGITUD_MAXIMA) {
            $fail('La dirección admite hasta '.SlugsReservados::LONGITUD_MAXIMA.' caracteres.');

            return;
        }

        if (SlugsReservados::incluye($value)) {
            $fail("La dirección «{$value}» está reservada. Elige otra.");
        }
    }
}
