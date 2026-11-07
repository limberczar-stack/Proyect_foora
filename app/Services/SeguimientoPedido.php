<?php

namespace App\Services;

use App\Models\Pedido;

/**
 * Lo que un cliente puede ver del avance de su pedido. Se usa en la página y en la consulta
 * periódica, y por eso no incluye datos personales.
 */
class SeguimientoPedido
{
    /**
     * @var array<string, string>
     */
    private const MOTIVOS_CANCELACION = [
        'cliente_no_respondio' => 'No pudimos comunicarnos contigo.',
        'cliente_cancelo' => 'Cancelaste el pedido.',
        'pago_no_confirmado' => 'No se pudo confirmar el pago.',
        'cliente_no_llego' => 'No se recogió el pedido a tiempo.',
        'tiempo_espera_excedido' => 'Se superó el tiempo de espera.',
        'problema_delivery' => 'Hubo un problema con el delivery.',
        'restaurante_rechazo' => 'El local no pudo atender el pedido.',
        'otro' => 'El local canceló el pedido.',
    ];

    /**
     * @return array{estado: string, final: bool, cancelado: bool, motivo: ?string, titulo: string, detalle: string, pago: string, pasos: list<array{clave: string, texto: string, estado: string}>}
     */
    public function datos(Pedido $pedido): array
    {
        $cancelado = $pedido->estado === 'cancelado';
        [$titulo, $detalle] = $this->textos($pedido);

        return [
            'estado' => $pedido->estado,
            'final' => $cancelado || $pedido->estado === 'entregado',
            'cancelado' => $cancelado,
            'motivo' => $cancelado ? (self::MOTIVOS_CANCELACION[$pedido->cancelacion_motivo] ?? self::MOTIVOS_CANCELACION['otro']) : null,
            'titulo' => $titulo,
            'detalle' => $detalle,
            'pago' => $this->textoPago($pedido),
            'pasos' => $this->pasos($pedido),
        ];
    }

    /**
     * @return list<array{clave: string, texto: string, estado: string}>
     */
    private function pasos(Pedido $pedido): array
    {
        $pasos = $pedido->tipo === 'delivery'
            ? ['nuevo' => 'Recibido', 'en_preparacion' => 'En preparación', 'listo' => 'Listo', 'en_camino' => 'En camino', 'entregado' => 'Entregado']
            : ['nuevo' => 'Recibido', 'en_preparacion' => 'En preparación', 'listo' => 'Para recoger', 'entregado' => 'Entregado'];

        $posicion = array_search($pedido->estado, array_keys($pasos), true);
        $resultado = [];

        foreach (array_keys($pasos) as $indice => $clave) {
            $resultado[] = [
                'clave' => $clave,
                'texto' => $pasos[$clave],
                'estado' => match (true) {
                    $posicion === false => 'pendiente',
                    $indice < $posicion || $pedido->estado === 'entregado' => 'hecho',
                    $indice === $posicion => 'actual',
                    default => 'pendiente',
                },
            ];
        }

        return $resultado;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function textos(Pedido $pedido): array
    {
        $esDelivery = $pedido->tipo === 'delivery';

        return match ($pedido->estado) {
            'en_preparacion' => ['En preparación', 'El local ya está preparando tu pedido.'],
            'listo' => $esDelivery ? ['Listo', 'Tu pedido saldrá pronto.'] : ['Para recoger', 'Ya puedes pasar por tu pedido.'],
            'en_camino' => ['En camino', 'Tu pedido va hacia tu dirección.'],
            'entregado' => ['Entregado', '¡Gracias por tu pedido!'],
            'cancelado' => ['Cancelado', 'Este pedido fue cancelado.'],
            default => ['Recibido', 'El local revisará tu pedido en breve.'],
        };
    }

    private function textoPago(Pedido $pedido): string
    {
        return match ($pedido->estado_pago) {
            'por_verificar' => 'El local está verificando tu pago.',
            'pagado' => 'Pago confirmado.',
            'rechazado' => 'El local no pudo confirmar tu pago.',
            default => 'Pagas al recibir tu pedido.',
        };
    }
}
