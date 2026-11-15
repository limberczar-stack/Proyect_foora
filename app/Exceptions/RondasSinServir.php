<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cerrar una cuenta con rondas que aún no se sirvieron: se responde 409 con la lista para que
 * quien cierra confirme. Al confirmar, esas rondas se marcan servidas.
 */
class RondasSinServir extends ConflictHttpException
{
    /**
     * @param  list<array{id: int, numero: int, ronda: ?int, estado: string}>  $rondas
     */
    public function __construct(public readonly array $rondas)
    {
        parent::__construct(count($rondas) === 1
            ? 'Hay 1 ronda sin servir. Confirma que ya se entregó para cerrar la cuenta.'
            : 'Hay '.count($rondas).' rondas sin servir. Confirma que ya se entregaron para cerrar la cuenta.');
    }
}
