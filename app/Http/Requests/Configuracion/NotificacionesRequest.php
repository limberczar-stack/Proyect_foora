<?php

namespace App\Http\Requests\Configuracion;

use App\Models\Restaurante;

/**
 * Avisos del panel. Por ahora solo se guardan; cada módulo los usará cuando llegue.
 */
class NotificacionesRequest extends ConfiguracionRequest
{
    protected function prepareForValidation(): void
    {
        $datos = [];

        foreach (array_keys(Restaurante::NOTIFICACIONES_POR_DEFECTO) as $clave) {
            $datos[$clave] = $this->boolean($clave);
        }

        $this->merge($datos);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return array_fill_keys(array_keys(Restaurante::NOTIFICACIONES_POR_DEFECTO), ['boolean']);
    }
}
