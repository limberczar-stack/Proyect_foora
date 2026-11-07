<?php

namespace App\Support;

/**
 * Enlaces wa.me y tel: para celulares peruanos.
 */
final class WhatsApp
{
    /**
     * Número internacional sin signos (51 + 9 dígitos), o null si no es un celular válido.
     */
    public static function numero(?string $telefono): ?string
    {
        $digitos = preg_replace('/[^0-9]/', '', (string) $telefono);

        if (strlen($digitos) === 9) {
            $digitos = '51'.$digitos;
        }

        return strlen($digitos) === 11 && str_starts_with($digitos, '51') ? $digitos : null;
    }

    public static function enlace(?string $telefono, ?string $mensaje = null): ?string
    {
        $numero = self::numero($telefono);

        if ($numero === null) {
            return null;
        }

        return "https://wa.me/{$numero}".($mensaje !== null ? '?text='.rawurlencode($mensaje) : '');
    }

    public static function llamada(?string $telefono): ?string
    {
        $numero = self::numero($telefono);

        return $numero === null ? null : "tel:+{$numero}";
    }
}
