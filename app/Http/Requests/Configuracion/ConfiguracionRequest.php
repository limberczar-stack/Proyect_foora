<?php

namespace App\Http\Requests\Configuracion;

use App\Models\Restaurante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base de las pestañas de Configuración: solo el admin edita, y siempre su propio local
 * (el restaurante sale de la sesión, nunca de lo que envía el formulario).
 */
abstract class ConfiguracionRequest extends FormRequest
{
    /**
     * Gate::authorize para que el 403 lleve el mensaje de la policy («Selecciona un restaurante.»).
     */
    public function authorize(): bool
    {
        Gate::authorize('configurar', $this->restaurante() ?? new Restaurante);

        return true;
    }

    public function restaurante(): ?Restaurante
    {
        return $this->user()?->restaurante;
    }

    /**
     * Quita espacios, guiones y el prefijo +51 de un celular escrito a mano.
     */
    protected function celularNormalizado(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $digitos = (string) preg_replace('/[\s\-().]/', '', $valor);

        return preg_replace('/\A(\+?51)(9\d{8})\z/', '$2', $digitos);
    }
}
