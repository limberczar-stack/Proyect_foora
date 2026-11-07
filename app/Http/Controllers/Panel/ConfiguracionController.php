<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\GeneralRequest;
use App\Http\Requests\Configuracion\HorarioRequest;
use App\Http\Requests\Configuracion\NotificacionesRequest;
use App\Http\Requests\Configuracion\PagoRequest;
use App\Http\Requests\Configuracion\PedidosRequest;
use App\Models\HorarioAtencion;
use App\Models\MetodoPago;
use App\Models\Restaurante;
use App\Models\RestauranteMetodoPago;
use App\Models\Rubro;
use App\Models\User;
use App\Services\RegistrarActividad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Configuración del local, una pestaña por sección. Solo el admin entra (RestaurantePolicy::configurar).
 * Cada guardado responde JSON por AJAX o redirige con mensaje sin JavaScript, y queda en actividad_log.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private RegistrarActividad $actividad) {}

    public function general(Request $request): View
    {
        $restaurante = $this->autorizar($request);

        return $this->vista('general', $restaurante, [
            'rubros' => Rubro::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function actualizarGeneral(GeneralRequest $request): JsonResponse|RedirectResponse
    {
        $restaurante = $request->restaurante();
        $datos = $request->validated();
        $antes = $this->fotoGeneral($restaurante);
        $logoAnterior = $restaurante->logo;
        $logoNuevo = $request->hasFile('logo') ? $request->file('logo')->store('logos', 'public') : null;

        try {
            DB::transaction(function () use ($restaurante, $datos, $logoNuevo): void {
                $restaurante->fill([
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'] ?? null,
                    'telefono' => $datos['telefono'] ?? null,
                    'email' => $datos['email'] ?? null,
                    'direccion' => $datos['direccion'] ?? null,
                    'ciudad' => $datos['ciudad'],
                    'rubro_id' => $datos['rubro_id'] ?? null,
                ]);

                if ($logoNuevo) {
                    $restaurante->logo = $logoNuevo;
                } elseif ($datos['quitar_logo']) {
                    $restaurante->logo = null;
                }

                $restaurante->save();
            });
        } catch (Throwable $e) {
            if ($logoNuevo) {
                Storage::disk('public')->delete($logoNuevo);
            }

            throw $e;
        }

        if ($logoAnterior && $logoAnterior !== $restaurante->logo && ! str_starts_with($logoAnterior, 'http')) {
            Storage::disk('public')->delete($logoAnterior);
        }

        $this->auditar($request->user(), $restaurante, 'actualizo_general', 'Actualizó los datos generales del local.', $antes, $this->fotoGeneral($restaurante));

        return $this->responder($request, 'general', 'Se guardaron los datos del local.', [
            'logo' => $restaurante->logoUrl(),
            'nombre' => $restaurante->nombre,
        ]);
    }

    public function horario(Request $request): View
    {
        $restaurante = $this->autorizar($request);

        return $this->vista('horario', $restaurante, [
            'horarios' => $restaurante->horariosAtencion()->get()->keyBy('dia_semana'),
            'dias' => HorarioRequest::DIAS,
        ]);
    }

    public function actualizarHorario(HorarioRequest $request): JsonResponse|RedirectResponse
    {
        $restaurante = $request->restaurante();
        $dias = $request->validated('dias');
        $antes = $this->fotoHorario($restaurante);

        DB::transaction(function () use ($restaurante, $dias): void {
            foreach (array_keys(HorarioRequest::DIAS) as $dia) {
                $fila = $dias[$dia];

                HorarioAtencion::updateOrCreate(
                    ['restaurante_id' => $restaurante->id, 'dia_semana' => $dia],
                    [
                        'cerrado' => $fila['cerrado'],
                        'abre' => $fila['cerrado'] ? null : $fila['abre'],
                        'cierra' => $fila['cerrado'] ? null : $fila['cierra'],
                    ],
                );
            }
        });

        $this->auditar($request->user(), $restaurante, 'actualizo_horario', 'Actualizó el horario de atención.', $antes, $this->fotoHorario($restaurante));

        return $this->responder($request, 'horario', 'Se guardó el horario de atención.');
    }

    public function pago(Request $request): View
    {
        $restaurante = $this->autorizar($request);

        return $this->vista('pago', $restaurante, [
            'metodos' => $this->metodosConfigurados($restaurante),
        ]);
    }

    public function actualizarPago(PagoRequest $request): JsonResponse|RedirectResponse
    {
        $restaurante = $request->restaurante();
        $metodos = $request->validated('metodos');
        $antes = $this->fotoPago($restaurante);
        $catalogo = MetodoPago::whereIn('codigo', PagoRequest::CODIGOS)->get()->keyBy('codigo');

        DB::transaction(function () use ($restaurante, $metodos, $catalogo): void {
            foreach (PagoRequest::CODIGOS as $codigo) {
                $metodo = $catalogo->get($codigo);

                if ($metodo === null) {
                    continue;
                }

                $conCuenta = in_array($codigo, PagoRequest::CON_CUENTA, true);

                RestauranteMetodoPago::updateOrCreate(
                    ['restaurante_id' => $restaurante->id, 'metodo_pago_id' => $metodo->id],
                    [
                        'activo' => $metodos[$codigo]['activo'],
                        'numero_cuenta' => $conCuenta ? ($metodos[$codigo]['numero_cuenta'] ?? null) : null,
                        'titular' => $conCuenta ? ($metodos[$codigo]['titular'] ?? null) : null,
                    ],
                );
            }
        });

        $this->auditar($request->user(), $restaurante, 'actualizo_pagos', 'Actualizó los métodos de pago.', $antes, $this->fotoPago($restaurante));

        return $this->responder($request, 'pago', 'Se guardaron los métodos de pago.');
    }

    public function pedidos(Request $request): View
    {
        return $this->vista('pedidos', $this->autorizar($request));
    }

    public function actualizarPedidos(PedidosRequest $request): JsonResponse|RedirectResponse
    {
        $restaurante = $request->restaurante();
        $antes = $this->fotoPedidos($restaurante);

        $restaurante->fill($request->safe()->only([
            ...PedidosRequest::INTERRUPTORES,
            'costo_delivery',
            'pedido_minimo',
            'tiempo_prep_min',
        ]))->save();

        $this->auditar($request->user(), $restaurante, 'actualizo_pedidos', 'Actualizó cómo recibe pedidos el local.', $antes, $this->fotoPedidos($restaurante));

        return $this->responder($request, 'pedidos', 'Se guardó la configuración de pedidos.');
    }

    public function notificaciones(Request $request): View
    {
        $restaurante = $this->autorizar($request);

        return $this->vista('notificaciones', $restaurante, [
            'notificaciones' => $restaurante->notificaciones(),
        ]);
    }

    public function actualizarNotificaciones(NotificacionesRequest $request): JsonResponse|RedirectResponse
    {
        $restaurante = $request->restaurante();
        $antes = $restaurante->notificaciones();

        $restaurante->forceFill(['config_notificaciones' => $request->validated()])->save();

        $this->auditar($request->user(), $restaurante, 'actualizo_notificaciones', 'Actualizó los avisos del panel.', $antes, $restaurante->notificaciones());

        return $this->responder($request, 'notificaciones', 'Se guardaron los avisos.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function vista(string $seccion, Restaurante $restaurante, array $extra = []): View
    {
        return view('panel.configuracion.show', [
            'seccion' => $seccion,
            'restaurante' => $restaurante,
            ...$extra,
        ]);
    }

    private function autorizar(Request $request): Restaurante
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $restaurante = $usuario->restaurante;

        Gate::authorize('configurar', $restaurante ?? new Restaurante);

        return $restaurante;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function responder(Request $request, string $seccion, string $mensaje, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensaje' => $mensaje, ...$extra]);
        }

        return redirect()->route("panel.configuracion.{$seccion}")->with('status', $mensaje);
    }

    /**
     * Guarda solo lo que cambió. Si nada cambió, no deja registro.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     */
    private function auditar(User $usuario, Restaurante $restaurante, string $accion, string $descripcion, array $antes, array $despues): void
    {
        [$cambiosAntes, $cambiosDespues] = RegistrarActividad::diferencias($antes, $despues);

        if ($cambiosDespues === []) {
            return;
        }

        $this->actividad->registrar($usuario, 'configuracion', $accion, $descripcion, $restaurante, $cambiosAntes, $cambiosDespues);
    }

    /**
     * Métodos del catálogo con lo que el local tiene guardado de cada uno.
     *
     * @return list<array{codigo: string, nombre: string, activo: bool, numero_cuenta: ?string, titular: ?string, con_cuenta: bool}>
     */
    private function metodosConfigurados(Restaurante $restaurante): array
    {
        $guardados = RestauranteMetodoPago::where('restaurante_id', $restaurante->id)->get()->keyBy('metodo_pago_id');
        $catalogo = MetodoPago::whereIn('codigo', PagoRequest::CODIGOS)->get()->keyBy('codigo');
        $metodos = [];

        foreach (PagoRequest::CODIGOS as $codigo) {
            $metodo = $catalogo->get($codigo);

            if ($metodo === null) {
                continue;
            }

            $guardado = $guardados->get($metodo->id);

            $metodos[] = [
                'codigo' => $codigo,
                'nombre' => $metodo->nombre,
                'activo' => (bool) $guardado?->activo,
                'numero_cuenta' => $guardado?->numero_cuenta,
                'titular' => $guardado?->titular,
                'con_cuenta' => in_array($codigo, PagoRequest::CON_CUENTA, true),
            ];
        }

        return $metodos;
    }

    /**
     * @return array<string, mixed>
     */
    private function fotoGeneral(Restaurante $restaurante): array
    {
        return $restaurante->only(['nombre', 'descripcion', 'telefono', 'email', 'direccion', 'ciudad', 'rubro_id', 'logo']);
    }

    /**
     * @return array<string, string>
     */
    private function fotoHorario(Restaurante $restaurante): array
    {
        $horarios = HorarioAtencion::where('restaurante_id', $restaurante->id)->get()->keyBy('dia_semana');
        $foto = [];

        foreach (HorarioRequest::DIAS as $dia => $nombre) {
            $horario = $horarios->get($dia);
            $foto[$nombre] = match (true) {
                $horario === null => 'sin definir',
                $horario->atiende() => $horario->rangoTexto(),
                default => 'cerrado',
            };
        }

        return $foto;
    }

    /**
     * @return array<string, array{activo: bool, numero_cuenta: ?string, titular: ?string}>
     */
    private function fotoPago(Restaurante $restaurante): array
    {
        $foto = [];

        foreach ($this->metodosConfigurados($restaurante) as $metodo) {
            $foto[$metodo['codigo']] = [
                'activo' => $metodo['activo'],
                'numero_cuenta' => $metodo['numero_cuenta'],
                'titular' => $metodo['titular'],
            ];
        }

        return $foto;
    }

    /**
     * @return array<string, mixed>
     */
    private function fotoPedidos(Restaurante $restaurante): array
    {
        $foto = $restaurante->only([...PedidosRequest::INTERRUPTORES, 'costo_delivery', 'pedido_minimo', 'tiempo_prep_min']);
        $foto['costo_delivery'] = number_format((float) $foto['costo_delivery'], 2, '.', '');
        $foto['pedido_minimo'] = number_format((float) $foto['pedido_minimo'], 2, '.', '');

        return $foto;
    }
}
