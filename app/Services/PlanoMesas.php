<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cuenta;
use App\Models\OcupacionMesa;
use App\Models\Pedido;
use App\Models\Restaurante;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Lo que muestra el plano de mesas a quien atiende: áreas y mesas activas, y para cada mesa
 * ocupada su cuenta (tiempo, comensales, mesero, grupo de mesas unidas, total y si hay rondas
 * listas para servir).
 *
 * El número de consultas es fijo: no crece con las mesas ni con las cuentas (lo prueba PlanoMesasTest).
 */
class PlanoMesas
{
    /**
     * @return array{
     *     areas: EloquentCollection<int, Area>,
     *     ocupaciones: Collection<int, array{cuenta_id: int, numero: int, version: int, tiempo: string, minutos: int, comensales: int, mesero: ?string, grupo: ?string, demorada: bool, total_centimos: int, listas: int, precuenta: bool}>,
     *     resumen: array{disponible: int, ocupada: int, fuera_servicio: int, total: int},
     * }
     */
    public function para(Restaurante $restaurante): array
    {
        $areas = Area::withoutGlobalScopes()
            ->where('restaurante_id', $restaurante->id)
            ->where('activa', true)
            ->ordenadas()
            ->with(['mesas' => fn ($query) => $query->withoutGlobalScopes()->where('activa', true)->ordenadas()])
            ->get();

        $mesas = $areas->flatMap->mesas;
        $codigos = $mesas->pluck('codigo', 'id');

        $abiertas = OcupacionMesa::withoutGlobalScopes()
            ->where('restaurante_id', $restaurante->id)
            ->whereNull('salida_at')
            ->whereNotNull('cuenta_id')
            ->orderBy('id')
            ->with(['cuenta' => fn ($query) => $query->withoutGlobalScopes()->with('mesero:id,name')])
            ->get();

        // Una sola consulta para el total y las rondas listas de todas las cuentas abiertas.
        $montos = $abiertas->isEmpty() ? collect() : Pedido::withoutGlobalScopes()
            ->whereIn('cuenta_id', $abiertas->pluck('cuenta_id')->unique()->values())
            ->where('estado', '!=', 'cancelado')
            ->selectRaw("cuenta_id, coalesce(sum(total), 0) as total, sum(case when estado = 'listo' then 1 else 0 end) as listas")
            ->groupBy('cuenta_id')
            ->toBase()
            ->get()
            ->keyBy('cuenta_id');

        $ocupaciones = collect();

        foreach ($abiertas->groupBy('cuenta_id') as $delGrupo) {
            /** @var Cuenta $cuenta */
            $cuenta = $delGrupo->first()->cuenta;
            $grupo = $delGrupo->map(fn (OcupacionMesa $ocupacion): string => (string) ($codigos[$ocupacion->mesa_id] ?? '?'));
            $minutos = $cuenta->minutosAbierta();

            $datos = [
                'cuenta_id' => $cuenta->id,
                'numero' => $cuenta->numero,
                'version' => $cuenta->version,
                'tiempo' => $cuenta->tiempoAbierta(),
                'minutos' => $minutos,
                'comensales' => $cuenta->comensales,
                'mesero' => $cuenta->mesero?->name,
                'grupo' => $grupo->count() > 1 ? $grupo->implode(' + ') : null,
                'demorada' => $minutos >= GestionarCuentas::HORAS_PARA_RESALTAR * 60,
                'total_centimos' => (int) round((float) ($montos[$cuenta->id]->total ?? 0) * 100),
                'listas' => (int) ($montos[$cuenta->id]->listas ?? 0),
                'precuenta' => $cuenta->estado === 'precuenta',
            ];

            foreach ($delGrupo as $ocupacion) {
                $ocupaciones[$ocupacion->mesa_id] = $datos;
            }
        }

        return [
            'areas' => $areas,
            'ocupaciones' => $ocupaciones,
            'resumen' => [
                'disponible' => $mesas->where('estado', 'disponible')->count(),
                'ocupada' => $mesas->where('estado', 'ocupada')->count(),
                'fuera_servicio' => $mesas->where('estado', 'fuera_servicio')->count(),
                'total' => $mesas->count(),
            ],
        ];
    }
}
