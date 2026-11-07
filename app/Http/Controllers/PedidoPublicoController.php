<?php

namespace App\Http\Controllers;

use App\Http\Requests\PedidoRequest;
use App\Models\Pedido;
use App\Models\Restaurante;
use App\Services\CartaPublica;
use App\Services\CrearPedido;
use App\Services\SeguimientoPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Pedido desde la vitrina y su seguimiento sin cuenta. El código de la URL es el único acceso.
 */
class PedidoPublicoController extends Controller
{
    public function store(PedidoRequest $request, string $slug, CartaPublica $carta, CrearPedido $crearPedido): JsonResponse
    {
        $pedido = $crearPedido->crear($carta->restauranteConDatos($slug), [
            ...$request->safe()->except(['lineas', 'acepta_privacidad']),
            'lineas' => $request->lineas(),
        ]);

        return response()->json([
            'codigo' => $pedido->codigo_seguimiento,
            'url' => route('vitrina.pedido.seguimiento', [$slug, $pedido->codigo_seguimiento]),
        ], $pedido->wasRecentlyCreated ? 201 : 200);
    }

    public function show(string $slug, string $codigo, SeguimientoPedido $seguimiento): Response
    {
        $restaurante = Restaurante::where('slug', $slug)->firstOrFail();
        $pedido = $this->pedido($restaurante, $codigo);

        if (! $pedido) {
            return response()->view('vitrina.pedido_no_encontrado', ['restaurante' => $restaurante], 404);
        }

        return response()
            ->view('vitrina.seguimiento', [
                'restaurante' => $restaurante,
                'pedido' => $pedido->load(['items.opciones', 'metodoPago']),
                'seguimiento' => $seguimiento->datos($pedido),
            ])
            ->withHeaders($this->cabecerasPrivadas());
    }

    /**
     * Consulta periódica de la pantalla de seguimiento.
     */
    public function estado(string $slug, string $codigo, SeguimientoPedido $seguimiento): JsonResponse
    {
        $pedido = $this->pedido(Restaurante::where('slug', $slug)->firstOrFail(), $codigo);

        abort_unless($pedido !== null, 404);

        return response()->json($seguimiento->datos($pedido))->withHeaders($this->cabecerasPrivadas());
    }

    private function pedido(Restaurante $restaurante, string $codigo): ?Pedido
    {
        return Pedido::withoutGlobalScope(Pedido::RESTAURANTE_SCOPE)
            ->where('restaurante_id', $restaurante->id)
            ->where('codigo_seguimiento', $codigo)
            ->first();
    }

    /**
     * La URL contiene el código: no debe guardarse en cachés ni viajar como referer.
     *
     * @return array<string, string>
     */
    private function cabecerasPrivadas(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex'];
    }
}
