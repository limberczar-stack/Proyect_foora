<?php

namespace App\Http\Requests\Superadmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base de los formularios del superadmin: nadie más los puede enviar.
 */
abstract class SuperadminRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('superadmin');

        return true;
    }

    /**
     * Quita espacios, guiones y el prefijo +51 de un celular escrito a mano.
     */
    protected function celularNormalizado(mixed $valor): mixed
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        return preg_replace('/\A(\+?51)(9\d{8})\z/', '$2', (string) preg_replace('/[\s\-().]/', '', $valor));
    }
}
