<?php

namespace App\Services;

use App\Models\ActividadLog;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Deja constancia en actividad_log de un cambio hecho desde el panel: quién, desde dónde y qué cambió.
 *
 * Los datos pasan por un filtro que quita contraseñas y tokens a cualquier profundidad: nunca se guardan.
 */
class RegistrarActividad
{
    /**
     * Claves que jamás se guardan, aunque lleguen en los datos.
     *
     * @var list<string>
     */
    public const CLAVES_SENSIBLES = [
        'password',
        'password_actual',
        'password_confirmation',
        'password_temporal',
        'remember_token',
        'token',
        '_token',
        'api_token',
    ];

    public function __construct(private Request $request) {}

    /**
     * @param  'pedidos'|'reservas'|'mesas'|'promociones'|'menu'|'configuracion'|'usuarios'|'suscripciones'  $modulo
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     * @param  Restaurante|null  $restaurante  el local afectado, cuando quien actúa es el superadmin (no tiene local propio)
     */
    public function registrar(
        User $actor,
        string $modulo,
        string $accion,
        string $descripcion,
        ?Model $sujeto = null,
        ?array $antes = null,
        ?array $despues = null,
        ?Restaurante $restaurante = null,
    ): ?ActividadLog {
        $restauranteId = $restaurante?->id ?? $actor->restaurante_id;

        if ($restauranteId === null) {
            return null;
        }

        return ActividadLog::create([
            'restaurante_id' => $restauranteId,
            'user_id' => $actor->id,
            'modulo' => $modulo,
            'accion' => Str::limit($accion, 40, ''),
            'descripcion' => Str::limit($descripcion, 255, ''),
            'subject_type' => $sujeto?->getMorphClass(),
            'subject_id' => $sujeto?->getKey(),
            'datos_antes' => $antes === null ? null : self::limpiar($antes),
            'datos_despues' => $despues === null ? null : self::limpiar($despues),
            'ip' => $this->request->ip(),
            'dispositivo' => self::dispositivo((string) $this->request->userAgent()),
            'navegador' => Str::limit(self::navegador((string) $this->request->userAgent()), 80, ''),
            'revertible' => false,
        ]);
    }

    /**
     * Cambio que hizo el sistema (comando programado): sin usuario, IP ni navegador.
     *
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    public function registrarDelSistema(
        Restaurante $restaurante,
        string $modulo,
        string $accion,
        string $descripcion,
        ?Model $sujeto = null,
        ?array $antes = null,
        ?array $despues = null,
    ): ActividadLog {
        return ActividadLog::create([
            'restaurante_id' => $restaurante->id,
            'user_id' => null,
            'modulo' => $modulo,
            'accion' => Str::limit($accion, 40, ''),
            'descripcion' => Str::limit($descripcion, 255, ''),
            'subject_type' => $sujeto?->getMorphClass(),
            'subject_id' => $sujeto?->getKey(),
            'datos_antes' => $antes === null ? null : self::limpiar($antes),
            'datos_despues' => $despues === null ? null : self::limpiar($despues),
            'dispositivo' => 'Sistema',
            'revertible' => false,
        ]);
    }

    /**
     * Solo las claves que cambiaron, en su versión anterior y nueva.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diferencias(array $antes, array $despues): array
    {
        $cambiosAntes = [];
        $cambiosDespues = [];

        foreach ($despues as $clave => $valor) {
            $anterior = $antes[$clave] ?? null;

            if ($anterior != $valor) {
                $cambiosAntes[$clave] = $anterior;
                $cambiosDespues[$clave] = $valor;
            }
        }

        return [$cambiosAntes, $cambiosDespues];
    }

    /**
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    public static function limpiar(array $datos): array
    {
        $limpios = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && in_array(Str::lower($clave), self::CLAVES_SENSIBLES, true)) {
                continue;
            }

            $limpios[$clave] = is_array($valor) ? self::limpiar($valor) : $valor;
        }

        return $limpios;
    }

    public static function dispositivo(string $agente): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $agente) => 'Tablet',
            (bool) preg_match('/Mobile|iPhone|iPod|Android|BlackBerry|Opera Mini|IEMobile/i', $agente) => 'Móvil',
            default => 'Web',
        };
    }

    public static function navegador(string $agente): string
    {
        return match (true) {
            $agente === '' => 'Desconocido',
            str_contains($agente, 'Edg/') => 'Edge',
            str_contains($agente, 'OPR/') || str_contains($agente, 'Opera') => 'Opera',
            str_contains($agente, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agente, 'Firefox/') || str_contains($agente, 'FxiOS') => 'Firefox',
            str_contains($agente, 'Chrome/') || str_contains($agente, 'CriOS') => 'Chrome',
            str_contains($agente, 'Safari/') => 'Safari',
            default => 'Otro',
        };
    }
}
