<?php

namespace App\Http\Controllers;

use App\Http\Requests\ValidarCarritoRequest;
use App\Services\CalculadorLineaPedido;
use App\Services\CartaPublica;
use App\Services\CrearPedido;
use App\Services\LineaPedidoCalculada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Vitrina pública de un restaurante: carta y validación del carrito, sin login.
 */
class VitrinaController extends Controller
{
    public function show(string $slug, CartaPublica $carta): View|Response
    {
        $restaurante = $carta->restauranteConDatos($slug);

        if (! $restaurante->operativo()) {
            return response()
                ->view('vitrina.no_disponible', ['restaurante' => $restaurante])
                ->header('X-Robots-Tag', 'noindex');
        }

        $secciones = $carta->secciones($restaurante);

        return view('vitrina.show', [
            'restaurante' => $restaurante,
            'secciones' => $secciones,
            'abierto' => $restaurante->abiertoAhora(),
            'datosVitrina' => [
                'slug' => $restaurante->slug,
                'urlValidar' => route('vitrina.carrito.validar', $restaurante->slug),
                'urlPedido' => route('vitrina.pedido.store', $restaurante->slug),
                'urlPedidos' => url("/{$restaurante->slug}/pedido"),
                'abierto' => $restaurante->abiertoAhora(),
                'costoDelivery' => $restaurante->costo_delivery,
                'pedidoMinimo' => $restaurante->pedido_minimo,
                'aceptaPedidos' => $restaurante->acepta_delivery || $restaurante->acepta_recojo,
                'lineasMaximas' => ValidarCarritoRequest::LINEAS_MAXIMAS,
                'cantidadMaxima' => CalculadorLineaPedido::CANTIDAD_MAXIMA,
                'productos' => $carta->productosParaNavegador($secciones),
            ],
        ]);
    }

    /**
     * Devuelve los precios oficiales del carrito y los errores de cada línea.
     */
    public function validarCarrito(ValidarCarritoRequest $request, string $slug, CartaPublica $carta, CalculadorLineaPedido $calculador): JsonResponse
    {
        $restaurante = $carta->restaurante($slug);

        if (! $restaurante->operativo()) {
            return response()->json(['message' => CrearPedido::MENSAJE_NO_DISPONIBLE], Response::HTTP_CONFLICT);
        }

        $resultado = $calculador->calcularCarrito($restaurante->id, $request->lineas());

        return response()->json([
            'lineas' => array_map(fn (array $fila): array => $this->lineaParaNavegador($fila['linea'], $fila['errores']), $resultado['lineas']),
            'subtotal' => $resultado['subtotal'],
        ]);
    }

    /**
     * @param  list<string>  $errores
     * @return array<string, mixed>
     */
    private function lineaParaNavegador(?LineaPedidoCalculada $linea, array $errores): array
    {
        return [
            'valida' => $linea !== null,
            'errores' => $errores,
            'nombre' => $linea?->nombre,
            'variante_nombre' => $linea?->varianteNombre,
            'opciones' => array_map(fn (array $opcion): array => [
                'grupo_nombre' => $opcion['grupo_nombre'],
                'nombre' => $opcion['nombre'],
                'precio_extra' => $opcion['precio_extra'],
            ], $linea->opciones ?? []),
            'precio_unitario' => $linea?->precioUnitario,
            'subtotal' => $linea?->subtotal,
        ];
    }
}
