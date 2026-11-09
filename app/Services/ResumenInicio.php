<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\HorarioAtencion;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Restaurante;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Datos del inicio del panel. Todo sale filtrado por restaurante, además del scope global, y con un
 * número fijo de consultas: no crece con los pedidos ni con las alertas.
 *
 * Reglas de las tarjetas, siempre con "hoy" según la zona del local:
 * - Pedidos de hoy: creados hoy, sin contar los cancelados.
 * - Ventas de hoy: suma del total de los pedidos entregados hoy, según entregado_at. Un pedido creado
 *   a las 23:50 y entregado a las 00:10 cuenta en las ventas del día en que se entregó.
 * - Activos: nuevos, en preparación, listos o en camino, de cualquier fecha: un pedido que quedó
 *   de ayer sigue por atender.
 * - Nuevos sin atender: estado nuevo.
 */
class ResumenInicio
{
    public const PEDIDOS_RECIENTES = 5;

    public const ALERTAS_VISIBLES = 5;

    /**
     * @return array{
     *     fecha: string,
     *     zonaHoraria: string,
     *     tarjetas: array{pedidosHoy: int, ventasHoy: float, entregadosHoy: int, activos: int, nuevos: int},
     *     tienePedidos: bool,
     *     recientes: Collection<int, Pedido>,
     *     alertas: list<array{prioridad: string, titulo: string, mensaje: ?string, hace: string, accion: array{etiqueta: string, url: string}|null}>,
     *     alertasTotal: int,
     *     alertasUrgentes: int,
     *     primerosPasos: array{pasos: array<string, array{titulo: string, detalle: string, completo: bool, accion: array{etiqueta: string, url: string}|null}>, completos: int, total: int, enlace: string}|null,
     * }
     */
    public function para(Restaurante $restaurante, bool $conPrimerosPasos): array
    {
        $zona = $restaurante->zona_horaria ?: Restaurante::ZONA_POR_DEFECTO;
        $recientes = $this->recientes($restaurante);
        $conteoAlertas = $this->conteoAlertas($restaurante);

        return [
            'fecha' => mb_ucfirst(Carbon::now($zona)->locale('es')->isoFormat('dddd D [de] MMMM')),
            'zonaHoraria' => $zona,
            'tarjetas' => $this->tarjetas($restaurante),
            'tienePedidos' => $recientes->isNotEmpty(),
            'recientes' => $recientes,
            'alertas' => $this->alertas($restaurante),
            'alertasTotal' => $conteoAlertas->sum(),
            'alertasUrgentes' => (int) ($conteoAlertas['urgente'] ?? 0),
            'primerosPasos' => $conPrimerosPasos ? $this->primerosPasos($restaurante, $recientes->isNotEmpty()) : null,
        ];
    }

    /**
     * @return array{pedidosHoy: int, ventasHoy: float, entregadosHoy: int, activos: int, nuevos: int}
     */
    private function tarjetas(Restaurante $restaurante): array
    {
        $inicio = $restaurante->inicioDeHoy();
        $fin = $inicio->copy()->addDay();

        $pedidosHoy = $this->pedidos($restaurante)
            ->where('created_at', '>=', $inicio)
            ->where('created_at', '<', $fin)
            ->where('estado', '!=', 'cancelado')
            ->count();

        $ventas = $this->pedidos($restaurante)
            ->where('estado', 'entregado')
            ->where('entregado_at', '>=', $inicio)
            ->where('entregado_at', '<', $fin)
            ->selectRaw('count(*) as entregados, coalesce(sum(total), 0) as ventas')
            ->toBase()
            ->first();

        $activos = $this->pedidos($restaurante)
            ->activos()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->toBase()
            ->pluck('total', 'estado');

        return [
            'pedidosHoy' => $pedidosHoy,
            'ventasHoy' => (float) $ventas->ventas,
            'entregadosHoy' => (int) $ventas->entregados,
            'activos' => (int) $activos->sum(),
            'nuevos' => (int) ($activos['nuevo'] ?? 0),
        ];
    }

    /**
     * @return Collection<int, Pedido>
     */
    private function recientes(Restaurante $restaurante): Collection
    {
        return $this->pedidos($restaurante)
            ->with('metodoPago')
            ->latest()
            ->latest('id')
            ->limit(self::PEDIDOS_RECIENTES)
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function conteoAlertas(Restaurante $restaurante): \Illuminate\Support\Collection
    {
        return $this->alertasPendientes($restaurante)
            ->selectRaw('prioridad, count(*) as total')
            ->groupBy('prioridad')
            ->toBase()
            ->pluck('total', 'prioridad')
            ->map(fn (mixed $total): int => (int) $total);
    }

    /**
     * Las urgentes primero; dentro de cada prioridad, la que lleva más tiempo esperando.
     *
     * @return list<array{prioridad: string, titulo: string, mensaje: ?string, hace: string, accion: array{etiqueta: string, url: string}|null}>
     */
    private function alertas(Restaurante $restaurante): array
    {
        return $this->alertasPendientes($restaurante)
            ->orderByRaw("case prioridad when 'urgente' then 0 when 'media' then 1 else 2 end")
            ->oldest()
            ->oldest('id')
            ->limit(self::ALERTAS_VISIBLES)
            ->get()
            ->map(fn (Alerta $alerta): array => [
                'prioridad' => $alerta->prioridad,
                'titulo' => $alerta->titulo,
                'mensaje' => $alerta->mensaje,
                'hace' => $alerta->created_at->locale('es')->diffForHumans(),
                'accion' => $this->accionDe($alerta),
            ])
            ->all();
    }

    /**
     * Por ahora solo los pedidos generan alertas. El enlace no carga el pedido: panel.pedidos.show lo
     * resuelve al abrirlo y aplica sus permisos.
     *
     * @return array{etiqueta: string, url: string}|null
     */
    private function accionDe(Alerta $alerta): ?array
    {
        if ($alerta->referencia_tipo !== 'pedido' || $alerta->referencia_id === null) {
            return null;
        }

        return [
            'etiqueta' => $alerta->tipo === 'pago_pendiente' ? 'Revisar pago' : 'Ver pedido',
            'url' => route('panel.pedidos.show', $alerta->referencia_id),
        ];
    }

    /**
     * La lista desaparece cuando todo está completo: entonces devuelve null.
     *
     * @return array{pasos: array<string, array{titulo: string, detalle: string, completo: bool, accion: array{etiqueta: string, url: string}|null}>, completos: int, total: int, enlace: string}|null
     */
    private function primerosPasos(Restaurante $restaurante, bool $tienePedidos): ?array
    {
        $tieneProducto = Producto::query()->where('restaurante_id', $restaurante->id)->exists();

        $tieneHorario = HorarioAtencion::query()
            ->where('restaurante_id', $restaurante->id)
            ->where('cerrado', false)
            ->whereNotNull('abre')
            ->whereNotNull('cierra')
            ->exists();

        $tienePago = $restaurante->loadMissing('metodosPago')->metodosPagoDisponibles()->isNotEmpty();

        $pasos = [
            'carta' => [
                'titulo' => 'Carga tu carta',
                'detalle' => 'Agrega al menos un producto para que tus clientes puedan pedir.',
                'completo' => $tieneProducto,
                'accion' => ['etiqueta' => 'Agregar producto', 'url' => route('panel.carta.productos.create')],
            ],
            'horario' => [
                'titulo' => 'Define tu horario',
                'detalle' => 'Tus clientes verán qué días y a qué horas atiendes.',
                'completo' => $tieneHorario,
                'accion' => ['etiqueta' => 'Definir horario', 'url' => route('panel.configuracion.horario')],
            ],
            'pago' => [
                'titulo' => 'Activa un método de pago',
                'detalle' => 'Elige cómo te pagan. Yape y Plin necesitan tu número.',
                'completo' => $tienePago,
                'accion' => ['etiqueta' => 'Configurar pagos', 'url' => route('panel.configuracion.pago')],
            ],
            'enlace' => [
                'titulo' => 'Comparte tu enlace',
                'detalle' => 'Envíalo a tus clientes. Este paso se completa con tu primer pedido.',
                'completo' => $tienePedidos,
                'accion' => null,
            ],
        ];

        $completos = count(array_filter($pasos, fn (array $paso): bool => $paso['completo']));

        if ($completos === count($pasos)) {
            return null;
        }

        return [
            'pasos' => $pasos,
            'completos' => $completos,
            'total' => count($pasos),
            'enlace' => $restaurante->urlVitrina(),
        ];
    }

    /**
     * @return Builder<Pedido>
     */
    private function pedidos(Restaurante $restaurante): Builder
    {
        return Pedido::query()->where('restaurante_id', $restaurante->id);
    }

    /**
     * @return Builder<Alerta>
     */
    private function alertasPendientes(Restaurante $restaurante): Builder
    {
        return Alerta::query()
            ->where('restaurante_id', $restaurante->id)
            ->whereNull('resuelta_at');
    }
}
