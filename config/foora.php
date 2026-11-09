<?php

return [

    /*
     * Contacto del equipo de FOORA que ven los locales en los avisos del panel.
     * Vacíos o inválidos, el aviso sale sin enlaces.
     */

    'contacto_whatsapp' => env('FOORA_CONTACTO_WHATSAPP'),

    'contacto_email' => env('FOORA_CONTACTO_EMAIL'),

    /*
     * Módulos del panel que aún no están construidos. Actívalos aquí cuando existan: aparecen en el
     * menú solo si además existe su ruta (App\Support\Modulos), así nunca hay un enlace sin destino.
     */

    'modulos' => [
        'alertas' => false,
        'reservas' => false,
        'mesas' => false,
        'promociones' => false,
        'actividad' => false,
        'ayuda' => false,
    ],

];
