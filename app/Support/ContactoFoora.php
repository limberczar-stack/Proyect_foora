<?php

namespace App\Support;

/**
 * Enlaces para que un local escriba al equipo de FOORA (config/foora.php).
 */
final class ContactoFoora
{
    /**
     * Cada enlace es null si su dato está vacío o no es válido: nunca se arma un enlace roto.
     *
     * @return array{whatsapp: ?string, email: ?string}
     */
    public static function enlaces(string $nombreLocal): array
    {
        $email = trim((string) config('foora.contacto_email'));

        return [
            'whatsapp' => WhatsApp::enlace(
                (string) config('foora.contacto_whatsapp'),
                "Hola FOORA, soy del local {$nombreLocal} y necesito ayuda con mi cuenta.",
            ),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL)
                ? "mailto:{$email}?subject=".rawurlencode("Cuenta del local {$nombreLocal}")
                : null,
        ];
    }
}
