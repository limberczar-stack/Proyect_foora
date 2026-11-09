<?php

namespace Database\Seeders;

use App\Models\Alerta;
use App\Models\Area;
use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\HorarioAtencion;
use App\Models\Mesa;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoHistorial;
use App\Models\PedidoItem;
use App\Models\PedidoItemOpcion;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Models\SuscripcionPago;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Datos de demostración: tres restaurantes completos y cuatro con cada estado de suscripción, para probar
 * login, roles y el aislamiento entre locales. No se llama desde DatabaseSeeder.
 *
 * Uso: php artisan db:seed --class=DemoSeeder
 * Contraseña de todos los usuarios: password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder no se ejecuta en producción (APP_ENV=production).');

            return;
        }

        // Catálogos (idempotentes): por si se ejecuta sin haber corrido DatabaseSeeder.
        $this->call([RubroSeeder::class, PlanSeeder::class, MetodoPagoSeeder::class]);

        $buenSabor = Restaurante::updateOrCreate(['slug' => 'el-buen-sabor'], [
            'rubro_id' => Rubro::where('nombre', 'Pollería')->value('id'),
            'plan_id' => Plan::where('nombre', 'Pro')->value('id'),
            'nombre' => 'Pollería El Buen Sabor',
            'telefono' => '962345678',
            'direccion' => 'Jr. Dos de Mayo 1020',
            'ciudad' => 'Huánuco',
            'descripcion' => 'Pollo a la brasa a la leña y parrillas, desde 2009.',
            'estado_local' => 'abierto',
            'visible_en_app' => true,
            'costo_delivery' => 5.00,
            'pedido_minimo' => 20.00,
            'tiempo_prep_min' => 35,
            'activo' => true,
        ]);

        $laBahia = Restaurante::updateOrCreate(['slug' => 'la-bahia'], [
            'rubro_id' => Rubro::where('nombre', 'Cevichería')->value('id'),
            'plan_id' => Plan::where('nombre', 'Básico')->value('id'),
            'nombre' => 'Cevichería La Bahía',
            'telefono' => '962876543',
            'direccion' => 'Av. Alameda de la República 455',
            'ciudad' => 'Huánuco',
            'descripcion' => 'Ceviches y pescados frescos, directo del mercado cada mañana.',
            'estado_local' => 'cerrado',
            'visible_en_app' => true,
            'acepta_delivery' => false,
            'pedido_minimo' => 0,
            'tiempo_prep_min' => 25,
            'activo' => true,
        ]);

        $dragonDorado = Restaurante::updateOrCreate(['slug' => 'chifa-dragon-dorado'], [
            'rubro_id' => Rubro::where('nombre', 'Chifa')->value('id'),
            'plan_id' => Plan::where('nombre', 'Pro')->value('id'),
            'nombre' => 'Chifa Dragón Dorado',
            'telefono' => '962112233',
            'direccion' => 'Jr. Huallayco 840',
            'ciudad' => 'Huánuco',
            'descripcion' => 'Chifa tradicional al wok, con porciones para compartir.',
            'estado_local' => 'abierto',
            'visible_en_app' => true,
            'costo_delivery' => 0,
            'pedido_minimo' => 25.00,
            'tiempo_prep_min' => 40,
            'acepta_pedidos_auto' => true,
            'activo' => true,
        ]);

        $this->usuario('superadmin@foora.test', 'Equipo FOORA', 'superadmin', null);
        $adminBuenSabor = $this->usuario('admin@foora.test', 'Rosa Quispe', 'admin', $buenSabor);
        $this->usuario('cajero@foora.test', 'Miguel Torres', 'cajero', $buenSabor);
        $this->usuario('cocina@foora.test', 'Carmen Flores', 'cocina', $buenSabor);
        $adminLaBahia = $this->usuario('admin@labahia.test', 'Carla Mendoza', 'admin', $laBahia);
        $adminDragon = $this->usuario('admin@chifa.test', 'Andrés Chang', 'admin', $dragonDorado);

        // De lunes a domingo; Dragón Dorado cierra de madrugada, y La Bahía no abre los lunes.
        $this->horarios($buenSabor, array_fill(1, 7, ['12:00', '22:00']));
        $this->horarios($laBahia, [1 => null, ...array_fill(2, 6, ['10:00', '16:00'])]);
        $this->horarios($dragonDorado, array_fill(1, 7, ['11:00', '02:00']));

        $this->metodosPago($buenSabor, ['efectivo', 'yape', 'plin', 'tarjeta'], '962345678', 'Rosa Quispe');
        $this->metodosPago($laBahia, ['efectivo', 'yape'], '962876543', 'Carla Mendoza');
        $this->metodosPago($dragonDorado, ['efectivo', 'yape', 'plin'], '962112233', 'Andrés Chang');

        $this->cartaBuenSabor($buenSabor);
        $this->cartaLaBahia($laBahia);
        $this->cartaDragonDorado($dragonDorado);

        $this->pedidos($buenSabor, $adminBuenSabor);
        $this->pedidos($laBahia, $adminLaBahia);
        $this->pedidos($dragonDorado, $adminDragon);

        $this->mesas($buenSabor, [
            'Salón principal' => ['M1' => 4, 'M2' => 2, 'M3' => 4, 'M4' => 4, 'M5' => 2, 'M6' => 6, 'M7' => 6, 'M8' => 4, 'M9' => 4, 'M10' => 8],
            'Terraza' => ['T1' => 2, 'T2' => 2, 'T3' => 4, 'T4' => 4, 'T5' => 4, 'T6' => 2],
        ]);

        $this->mesas($laBahia, [
            'Salón' => ['B1' => 4, 'B2' => 4, 'B3' => 2, 'B4' => 6],
        ]);

        $this->mesas($dragonDorado, [
            'Salón' => ['D1' => 4, 'D2' => 4, 'D3' => 6, 'D4' => 8, 'D5' => 2],
        ]);

        $this->suscripcion($buenSabor, 'activa', 30, 79, []);
        $this->suscripcion($laBahia, 'activa', 25, 39, []);
        $this->suscripcion($dragonDorado, 'activa', 12, 79, []);

        $this->localesDeSuscripcion();

        $this->command?->info('Demo lista. Usuarios: superadmin@foora.test, admin@foora.test, cajero@foora.test, cocina@foora.test, admin@labahia.test, admin@chifa.test, admin@prueba.test, admin@aldia.test, admin@gracia.test, admin@fuera.test (contraseña: password).');
    }

    /**
     * Cuatro locales para ver cada estado de la suscripción. Las fechas se cuentan desde hoy en Lima.
     */
    private function localesDeSuscripcion(): void
    {
        $locales = [
            ['cafe-la-prueba', 'Café La Prueba', 'Cafetería', 'Básico', 'admin@prueba.test', 'Lucía Prado', 'prueba', 3, 0, []],
            ['burger-al-dia', 'Burger Al Día', 'Fast food', 'Pro', 'admin@aldia.test', 'Hugo Salas', 'activa', 22, 79, [[79, 'Yape', 'YP-200481', -38, -8], [79, 'Transferencia', 'TR-773120', -8, 22]]],
            ['pollos-en-gracia', 'Pollos En Gracia', 'Pollería', 'Básico', 'admin@gracia.test', 'Nora Vega', 'vencida', -2, 39, [[39, 'Efectivo', null, -32, -2]]],
            ['cevicheria-fuera', 'Cevichería Fuera', 'Cevichería', 'Premium', 'admin@fuera.test', 'Iván Rojas', 'vencida', -12, 129, [[129, 'Plin', 'PL-558210', -42, -12]]],
        ];

        foreach ($locales as [$slug, $nombre, $rubro, $plan, $email, $admin, $estado, $dias, $precio, $pagos]) {
            $restaurante = Restaurante::updateOrCreate(['slug' => $slug], [
                'rubro_id' => Rubro::where('nombre', $rubro)->value('id'),
                'plan_id' => Plan::where('nombre', $plan)->value('id'),
                'nombre' => $nombre,
                'telefono' => '962000111',
                'direccion' => 'Jr. Abtao 310',
                'ciudad' => 'Huánuco',
                'estado_local' => 'abierto',
                'visible_en_app' => true,
                'activo' => true,
            ]);

            $this->usuario($email, $admin, 'admin', $restaurante);
            $this->horarios($restaurante, array_fill(1, 7, ['00:00', '23:59']));
            $this->metodosPago($restaurante, ['efectivo'], '962000111', $admin);
            $this->mesas($restaurante, ['Salón principal' => ['S1' => 4, 'S2' => 4]]);

            $carta = $this->categoria($restaurante, 'Carta', 0);
            $this->producto($carta, 'Menú del día', 'El plato del día con su bebida.', precio: 18.00);

            $this->suscripcion($restaurante, $estado, $dias, $precio, $pagos);
        }
    }

    /**
     * Deja al local con una sola suscripción y estos pagos. Es solo para la demo: la aplicación nunca borra pagos.
     *
     * @param  list<array{0: int, 1: string, 2: ?string, 3: int, 4: int}>  $pagos  monto, método, referencia, desde y hasta (días desde hoy)
     */
    private function suscripcion(Restaurante $restaurante, string $estado, int $dias, float $precio, array $pagos): void
    {
        $hoy = now(Restaurante::ZONA_POR_DEFECTO)->startOfDay();

        Suscripcion::withoutGlobalScope(Suscripcion::RESTAURANTE_SCOPE)->where('restaurante_id', $restaurante->id)->delete();

        $suscripcion = Suscripcion::create([
            'restaurante_id' => $restaurante->id,
            'plan_id' => $restaurante->plan_id,
            'estado' => $estado,
            'inicia_el' => $hoy->copy()->addDays(min($dias, 0) - 45)->toDateString(),
            'vence_el' => $hoy->copy()->addDays($dias)->toDateString(),
            'precio_pactado' => $precio,
        ]);

        $venceAntes = $suscripcion->inicia_el->toDateString();
        $estadoAntes = 'prueba';

        foreach ($pagos as [$monto, $metodo, $referencia, $desde, $hasta]) {
            SuscripcionPago::create([
                'suscripcion_id' => $suscripcion->id,
                'monto' => $monto,
                'metodo' => $metodo,
                'referencia' => $referencia,
                'periodo_desde' => $hoy->copy()->addDays($desde)->toDateString(),
                'periodo_hasta' => $hoy->copy()->addDays($hasta)->toDateString(),
                'pagado_at' => $hoy->copy()->addDays($desde)->setTimezone(config('app.timezone')),
                'vence_antes' => $venceAntes,
                'estado_antes' => $estadoAntes,
            ]);

            $venceAntes = $hoy->copy()->addDays($hasta)->toDateString();
            $estadoAntes = 'activa';
        }
    }

    /**
     * Pollería: categorías anidadas (Bebidas › Frías / Calientes, sin fotos) y variantes de presa y tamaño.
     */
    private function cartaBuenSabor(Restaurante $restaurante): void
    {
        $pollos = $this->categoria($restaurante, 'Pollos a la brasa', 0, descripcion: 'Pollo marinado a la leña con papas y ensalada');
        $parrillas = $this->categoria($restaurante, 'Parrillas', 1);
        $acompanamientos = $this->categoria($restaurante, 'Acompañamientos', 2);
        $bebidas = $this->categoria($restaurante, 'Bebidas', 3, conFotos: false);
        $frias = $this->categoria($restaurante, 'Frías', 0, padre: $bebidas, conFotos: false);
        $calientes = $this->categoria($restaurante, 'Calientes', 1, padre: $bebidas, conFotos: false);

        $cuartoPollo = $this->producto($pollos, '1/4 de pollo a la brasa', 'Con papas fritas, ensalada fresca y cremas de la casa.', variantes: ['Pierna' => 17.00, 'Pecho' => 18.50], destacado: true);
        $medioPollo = $this->producto($pollos, '1/2 pollo a la brasa', 'Con papas fritas familiares y ensalada.', precio: 32.00);
        $polloEntero = $this->producto($pollos, 'Pollo entero a la brasa', 'Con papas fritas familiares, ensalada y gaseosa de 1.5 L.', precio: 58.00, destacado: true);
        $this->producto($parrillas, 'Anticuchos de corazón', 'Dos palitos con papa dorada y choclo.', precio: 18.00, etiquetas: ['picante']);
        $parrillaPersonal = $this->producto($parrillas, 'Parrilla personal', null, precio: 38.00, etiquetas: ['nuevo']);
        $this->producto($acompanamientos, 'Papas fritas', 'Papa amarilla huanuqueña frita al momento.', variantes: ['Personal' => 8.00, 'Familiar' => 15.00], etiquetas: ['vegetariano']);
        $this->producto($acompanamientos, 'Ensalada fresca', 'Lechuga, tomate, pepino y zanahoria con vinagreta.', precio: 7.00, etiquetas: ['vegetariano', 'sin_gluten']);
        $this->producto($frias, 'Chicha morada', 'Preparada en casa con maíz morado.', variantes: ['Vaso' => 4.00, '1/2 litro' => 8.00, 'Jarra 1 L' => 14.00], estacion: 'barra');
        $this->producto($frias, 'Limonada', 'Limón de la zona, bien helada.', variantes: ['Vaso' => 5.00, '1/2 litro' => 9.00, 'Jarra 1 L' => 16.00], estacion: 'barra');
        $this->producto($frias, 'Inca Kola 500 ml', null, precio: 4.50, estacion: 'barra');
        $this->producto($calientes, 'Café pasado', 'Café de Chinchao.', precio: 5.00, estacion: 'barra');
        $this->producto($calientes, 'Infusión de manzanilla', 'Con miel de abeja.', precio: 3.50, estacion: 'barra', disponible: false);

        $guarnicion = $this->grupo($restaurante, 'Elige tu guarnición', ['Papas fritas' => 0, 'Ensalada' => 0, 'Arroz' => 0], obligatorio: true);
        $adicionales = $this->grupo($restaurante, 'Adicionales', ['Piña' => 3, 'Plátano' => 3, 'Huevo' => 3, 'Tocino' => 5], tipo: 'multiple');
        $gaseosa = $this->grupo($restaurante, 'Gaseosa', ['Coca Cola' => 0, 'Inca Kola' => 0, 'Fanta' => 0, 'Sprite' => 0], obligatorio: true);

        $this->asignarGrupos($cuartoPollo, [$guarnicion, $adicionales]);
        $this->asignarGrupos($medioPollo, [$guarnicion, $adicionales]);
        $this->asignarGrupos($polloEntero, [$guarnicion, $adicionales, $gaseosa]);
        $this->asignarGrupos($parrillaPersonal, [$adicionales]);
    }

    private function cartaLaBahia(Restaurante $restaurante): void
    {
        $ceviches = $this->categoria($restaurante, 'Ceviches', 0);
        $calientes = $this->categoria($restaurante, 'Platos calientes', 1);
        $bebidas = $this->categoria($restaurante, 'Bebidas', 2, conFotos: false);

        $this->producto($ceviches, 'Ceviche clásico', 'Pescado del día con limón, ají limo, camote y choclo.', precio: 28.00, etiquetas: ['picante', 'sin_gluten']);
        $this->producto($ceviches, 'Ceviche mixto', 'Pescado, pulpo, langostinos y conchas negras.', precio: 34.00, etiquetas: ['picante']);
        $this->producto($calientes, 'Chicharrón de pescado', 'Con yuca frita y salsa criolla.', precio: 30.00);
        $this->producto($calientes, 'Arroz con mariscos', 'Arroz norteño con mariscos y salsa criolla.', precio: 32.00);
        $this->producto($bebidas, 'Chicha morada', 'Jarra de 1 litro.', precio: 12.00, estacion: 'barra');
    }

    /**
     * Chifa: variantes de tamaño (Taza, Personal, Mediano, Familiar), elección de sabor y un combo.
     */
    private function cartaDragonDorado(Restaurante $restaurante): void
    {
        $tamanos = fn (float $taza, float $personal, float $mediano, float $familiar): array => [
            'Taza' => $taza, 'Personal' => $personal, 'Mediano' => $mediano, 'Familiar' => $familiar,
        ];

        $arroces = $this->categoria($restaurante, 'Arroces', 0);
        $tallarines = $this->categoria($restaurante, 'Tallarines', 1);
        $platos = $this->categoria($restaurante, 'Platos de la casa', 2);
        $entradas = $this->categoria($restaurante, 'Entradas y sopas', 3);
        $combos = $this->categoria($restaurante, 'Combos', 4);
        $bebidas = $this->categoria($restaurante, 'Bebidas', 5, conFotos: false);

        $this->producto($arroces, 'Chaufa de pollo', 'Arroz salteado al wok con pollo, huevo y cebollita china.', variantes: $tamanos(11.00, 22.00, 32.00, 44.00), destacado: true);
        $this->producto($arroces, 'Chaufa especial', 'Con pollo, chancho asado, langostinos y tortilla.', variantes: $tamanos(13.00, 25.00, 36.00, 49.00));
        $this->producto($arroces, 'Aeropuerto', 'Chaufa con tallarín saltado y tortilla de huevo.', variantes: ['Personal' => 24.00, 'Familiar' => 46.00]);
        $this->producto($tallarines, 'Tallarín saltado de pollo', 'Fideos salteados con verduras al wok.', variantes: ['Personal' => 22.00, 'Familiar' => 42.00]);
        $this->producto($entradas, 'Wantán frito', '10 unidades con salsa de tamarindo.', precio: 12.00);
        $this->producto($entradas, 'Sopa wantán', 'Caldo de pollo con wantanes y verduras.', variantes: ['Personal' => 14.00, 'Familiar' => 26.00]);
        $this->producto($bebidas, 'Té jazmín', null, variantes: ['Taza' => 3.00, 'Tetera' => 9.00], estacion: 'barra');

        $tipakay = $this->producto($platos, 'Tipakay de pollo', 'Pollo crocante con verduras al wok y arroz blanco.', precio: 26.00);
        $banquete = $this->producto($combos, 'Banquete Dragón para 5', 'Incluye chaufa familiar, tallarín saltado familiar, 20 wantanes fritos, tipakay de pollo y sopa wantán familiar.', precio: 129.00, destacado: true, personas: 5);

        $this->asignarGrupos($tipakay, [$this->grupo($restaurante, 'Sabor', ['Salado' => 0, 'Dulce' => 0], obligatorio: true)]);
        $this->asignarGrupos($banquete, [$this->grupo($restaurante, 'Chaufa de', ['Pollo' => 0, 'Chancho' => 0], obligatorio: true)]);
    }

    private function categoria(Restaurante $restaurante, string $nombre, int $orden, ?CategoriaProducto $padre = null, bool $conFotos = true, ?string $descripcion = null): CategoriaProducto
    {
        return CategoriaProducto::updateOrCreate(
            ['restaurante_id' => $restaurante->id, 'parent_id' => $padre?->id, 'nombre' => $nombre],
            ['orden' => $orden, 'activa' => true, 'con_fotos' => $conFotos, 'descripcion' => $descripcion],
        );
    }

    /**
     * @param  array<string, float>  $variantes  nombre => precio (si hay, el producto es de tipo variantes)
     * @param  list<string>  $etiquetas
     */
    private function producto(
        CategoriaProducto $categoria,
        string $nombre,
        ?string $descripcion,
        ?float $precio = null,
        array $variantes = [],
        array $etiquetas = [],
        string $estacion = 'cocina',
        bool $destacado = false,
        bool $disponible = true,
        ?int $personas = null,
    ): Producto {
        $producto = Producto::withTrashed()->updateOrCreate(
            ['restaurante_id' => $categoria->restaurante_id, 'nombre' => $nombre],
            [
                'categoria_id' => $categoria->id,
                'tipo' => $personas !== null ? 'combo' : ($variantes === [] ? 'simple' : 'variantes'),
                'personas' => $personas,
                'descripcion' => $descripcion,
                'precio' => $variantes === [] ? $precio : min($variantes),
                'estacion' => $estacion,
                'etiquetas' => $etiquetas,
                'destacado' => $destacado,
                'disponible' => $disponible,
            ],
        );

        if ($producto->trashed()) {
            $producto->restore();
        }

        // En el seeder sí se recrean: así repetirlo deja siempre los mismos datos.
        ProductoVariante::where('producto_id', $producto->id)->delete();

        foreach (array_keys($variantes) as $orden => $nombreVariante) {
            $producto->variantes()->create(['nombre' => $nombreVariante, 'precio' => $variantes[$nombreVariante], 'orden' => $orden]);
        }

        return $producto;
    }

    /**
     * @param  array<string, float|int>  $opciones  nombre => precio extra
     */
    private function grupo(Restaurante $restaurante, string $nombre, array $opciones, string $tipo = 'unica', bool $obligatorio = false): GrupoOpcion
    {
        $grupo = GrupoOpcion::updateOrCreate(
            ['restaurante_id' => $restaurante->id, 'nombre' => $nombre],
            [
                'tipo' => $tipo,
                'obligatorio' => $obligatorio,
                'min_opciones' => $obligatorio ? 1 : 0,
                'max_opciones' => $tipo === 'unica' ? 1 : null,
                'activo' => true,
            ],
        );

        // Igual que las variantes: repetir el seeder deja siempre los mismos datos.
        $grupo->opciones()->delete();

        foreach (array_keys($opciones) as $orden => $nombreOpcion) {
            $grupo->opciones()->create(['nombre' => $nombreOpcion, 'precio_extra' => $opciones[$nombreOpcion], 'orden' => $orden]);
        }

        return $grupo;
    }

    /**
     * @param  list<GrupoOpcion>  $grupos  en el orden que verá el cliente
     */
    private function asignarGrupos(Producto $producto, array $grupos): void
    {
        $producto->gruposOpcion()->sync(
            collect($grupos)->mapWithKeys(fn (GrupoOpcion $grupo, int $orden): array => [$grupo->id => ['orden' => $orden]]),
        );
    }

    /**
     * @param  array<int, array{0: string, 1: string}|null>  $dias  día (1=lunes … 7=domingo) => [abre, cierra], null si no atiende
     */
    private function horarios(Restaurante $restaurante, array $dias): void
    {
        foreach ($dias as $dia => $horario) {
            HorarioAtencion::updateOrCreate(
                ['restaurante_id' => $restaurante->id, 'dia_semana' => $dia],
                ['abre' => $horario[0] ?? null, 'cierra' => $horario[1] ?? null, 'cerrado' => $horario === null],
            );
        }
    }

    /**
     * @param  list<string>  $codigos
     */
    private function metodosPago(Restaurante $restaurante, array $codigos, string $numeroCuenta, string $titular): void
    {
        $restaurante->metodosPago()->sync(
            MetodoPago::whereIn('codigo', $codigos)->get()->mapWithKeys(fn (MetodoPago $metodo): array => [$metodo->id => [
                'activo' => true,
                'numero_cuenta' => $metodo->requiere_validacion ? $numeroCuenta : null,
                'titular' => $metodo->requiere_validacion ? $titular : null,
            ]]),
        );
    }

    /**
     * Pedidos de ejemplo en todos los estados, para ver el panel completo. Se recrean en cada
     * ejecución, con números desde 1.
     */
    private function pedidos(Restaurante $restaurante, User $responsable): void
    {
        Alerta::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->where('referencia_tipo', 'pedido')->delete();
        Pedido::withoutGlobalScopes()->where('restaurante_id', $restaurante->id)->delete();

        $productos = Producto::withoutGlobalScopes()
            ->where('restaurante_id', $restaurante->id)
            ->where('disponible', true)
            ->with(['variantes', 'gruposOpcion.opciones'])
            ->orderBy('id')
            ->get()
            ->values();
        $metodos = $restaurante->metodosPago()->get()->keyBy('codigo');
        $tipoEntrega = $restaurante->acepta_delivery ? 'delivery' : 'recojo';

        // [estado, tipo, método, minutos desde que llegó, cliente, nota de cocina]
        $escenarios = [
            ['nuevo', $tipoEntrega, 'yape', 3, 'Lucía Paredes', null],
            ['nuevo', 'recojo', 'efectivo', 6, 'Jorge Ramírez', 'Sin cremas'],
            ['nuevo', $tipoEntrega, 'efectivo', 9, 'María Salazar', null],
            ['en_preparacion', $tipoEntrega, 'efectivo', 15, 'Pedro Huamán', 'Bien cocido'],
            ['en_preparacion', 'recojo', 'yape', 22, 'Ana Chávez', null],
            ['en_preparacion', $tipoEntrega, 'efectivo', 26, 'Luis Vargas', null],
            ['listo', 'recojo', 'efectivo', 34, 'Rosa Espinoza', null],
            ['listo', $tipoEntrega, 'yape', 38, 'Carlos Rojas', 'Mucho ají aparte'],
            ['en_camino', $tipoEntrega, 'efectivo', 45, 'Sofía Mendoza', null],
            ['entregado', 'recojo', 'efectivo', 90, 'Diego Torres', null],
            ['entregado', $tipoEntrega, 'yape', 120, 'Valeria Cruz', null],
            ['cancelado', $tipoEntrega, 'efectivo', 60, 'Miguel Ponce', null],
        ];

        foreach ($escenarios as $indice => [$estado, $tipo, $codigoMetodo, $minutos, $cliente, $nota]) {
            $metodo = $metodos->get($codigoMetodo) ?? $metodos->get('efectivo');
            $this->pedido($restaurante, $responsable, $indice + 1, $estado, $tipo, $metodo, $minutos, $cliente, $nota, $productos);
        }
    }

    /**
     * @param  Collection<int, Producto>  $productos
     */
    private function pedido(Restaurante $restaurante, User $responsable, int $numero, string $estado, string $tipo, MetodoPago $metodo, int $minutos, string $cliente, ?string $nota, $productos): void
    {
        $llegada = now()->subMinutes($minutos);
        $enMinutos = fn (int $m) => $llegada->copy()->addMinutes($m);
        $esDelivery = $tipo === 'delivery';
        $porVerificar = $metodo->requiere_validacion;

        $lineas = [];
        foreach ([$productos[($numero * 2) % $productos->count()], $productos[($numero * 3 + 1) % $productos->count()]] as $orden => $producto) {
            $variante = $producto->variantes->first();
            $opciones = $producto->gruposOpcion->where('obligatorio', true)->map(fn (GrupoOpcion $grupo) => [$grupo, $grupo->opciones->first()])->filter(fn (array $par) => $par[1] !== null)->values();
            $unitario = (float) ($variante?->precio ?? $producto->precio) + $opciones->sum(fn (array $par) => (float) $par[1]->precio_extra);
            $cantidad = $orden === 0 ? 1 + $numero % 2 : 1;
            $lineas[] = compact('producto', 'variante', 'opciones', 'unitario', 'cantidad');
        }

        $subtotal = array_sum(array_map(fn (array $l) => $l['unitario'] * $l['cantidad'], $lineas));
        $delivery = $esDelivery ? (float) $restaurante->costo_delivery : 0.0;
        $estadoPago = match (true) {
            $estado === 'entregado' => 'pagado',
            $porVerificar && in_array($estado, ['en_preparacion', 'listo', 'en_camino'], true) => 'pagado',
            $porVerificar && $estado === 'nuevo' => 'por_verificar',
            default => 'pendiente',
        };

        $pedido = new Pedido;
        $pedido->forceFill([
            'restaurante_id' => $restaurante->id,
            'numero' => $numero,
            'codigo_seguimiento' => Str::random(Pedido::LONGITUD_CODIGO),
            'nombre_cliente' => $cliente,
            'telefono_cliente' => '9'.str_pad((string) (70000000 + $restaurante->id * 1000 + $numero), 8, '0', STR_PAD_LEFT),
            'tipo' => $tipo,
            'origen' => 'app',
            'direccion_entrega' => $esDelivery ? 'Jr. Huánuco '.(100 + $numero * 7) : null,
            'referencia_entrega' => $esDelivery ? 'Frente al parque, puerta verde' : null,
            'metodo_pago_id' => $metodo->id,
            'estado_pago' => $estadoPago,
            'referencia_pago' => $porVerificar ? 'OP'.(482000 + $numero) : null,
            'pago_validado_por' => $estadoPago === 'pagado' ? $responsable->id : null,
            'pago_validado_at' => $estadoPago === 'pagado' ? $enMinutos(2) : null,
            'subtotal' => $subtotal,
            'costo_delivery' => $delivery,
            'total' => $subtotal + $delivery,
            'estado' => $estado,
            'tiempo_estimado_min' => $restaurante->tiempo_prep_min,
            'aceptado_at' => in_array($estado, ['nuevo', 'cancelado'], true) ? null : $enMinutos(3),
            'listo_at' => in_array($estado, ['listo', 'en_camino', 'entregado'], true) ? $enMinutos(18) : null,
            'recoger_antes_de' => $tipo === 'recojo' && in_array($estado, ['listo', 'entregado'], true) ? $enMinutos(18 + Pedido::MINUTOS_PARA_RECOGER) : null,
            'salida_at' => $esDelivery && in_array($estado, ['en_camino', 'entregado'], true) ? $enMinutos(20) : null,
            'eta_at' => $esDelivery && in_array($estado, ['en_camino', 'entregado'], true) ? $enMinutos(40) : null,
            'entregado_at' => $estado === 'entregado' ? $enMinutos(35) : null,
            'cancelado_at' => $estado === 'cancelado' ? $enMinutos(5) : null,
            'cancelacion_motivo' => $estado === 'cancelado' ? 'cliente_no_respondio' : null,
            'consentimiento_at' => $llegada,
            'consentimiento_version' => Pedido::CONSENTIMIENTO_VERSION,
            'created_at' => $llegada,
            'updated_at' => $llegada,
        ])->save();

        foreach ($lineas as $orden => $linea) {
            $item = PedidoItem::create([
                'pedido_id' => $pedido->id,
                'producto_id' => $linea['producto']->id,
                'variante_id' => $linea['variante']?->id,
                'variante_nombre' => $linea['variante']?->nombre,
                'nombre' => $linea['producto']->nombre,
                'cantidad' => $linea['cantidad'],
                'precio_unitario' => $linea['unitario'],
                'subtotal' => $linea['unitario'] * $linea['cantidad'],
                'observacion' => $orden === 0 ? $nota : null,
            ]);

            foreach ($linea['opciones'] as [$grupo, $opcion]) {
                PedidoItemOpcion::create(['pedido_item_id' => $item->id, 'opcion_id' => $opcion->id, 'grupo_nombre' => $grupo->nombre, 'nombre' => $opcion->nombre, 'precio_extra' => $opcion->precio_extra]);
            }
        }

        $camino = match ($estado) {
            'nuevo' => ['nuevo'],
            'en_preparacion' => ['nuevo', 'en_preparacion'],
            'listo' => ['nuevo', 'en_preparacion', 'listo'],
            'en_camino' => ['nuevo', 'en_preparacion', 'listo', 'en_camino'],
            'entregado' => $esDelivery ? ['nuevo', 'en_preparacion', 'listo', 'en_camino', 'entregado'] : ['nuevo', 'en_preparacion', 'listo', 'entregado'],
            default => ['nuevo', 'cancelado'],
        };
        $momentos = ['nuevo' => 0, 'en_preparacion' => 3, 'listo' => 18, 'en_camino' => 20, 'entregado' => 35, 'cancelado' => 5];

        foreach ($camino as $paso => $estadoPaso) {
            $registro = new PedidoHistorial;
            $registro->forceFill([
                'pedido_id' => $pedido->id,
                'estado_anterior' => $paso === 0 ? null : $camino[$paso - 1],
                'estado_nuevo' => $estadoPaso,
                'user_id' => $paso === 0 ? null : $responsable->id,
                'nota' => match ($estadoPaso) {
                    'nuevo' => 'Pedido recibido desde la vitrina.',
                    'cancelado' => 'Motivo: '.Pedido::MOTIVOS_CANCELACION['cliente_no_respondio'].'.',
                    default => null,
                },
                'created_at' => $enMinutos($momentos[$estadoPaso]),
            ])->save();
        }

        if ($estadoPago === 'por_verificar') {
            Alerta::create(['restaurante_id' => $restaurante->id, 'tipo' => 'pago_pendiente', 'prioridad' => 'urgente', 'titulo' => "Pago por validar: pedido #{$numero}", 'mensaje' => "{$metodo->nombre} por S/ ".number_format($subtotal + $delivery, 2).'.', 'referencia_tipo' => 'pedido', 'referencia_id' => $pedido->id]);
        }

        if ($estado === 'nuevo') {
            Alerta::create(['restaurante_id' => $restaurante->id, 'tipo' => 'pedido_nuevo', 'prioridad' => 'media', 'titulo' => "Nuevo pedido #{$numero}", 'mensaje' => 'Total S/ '.number_format($subtotal + $delivery, 2).'.', 'referencia_tipo' => 'pedido', 'referencia_id' => $pedido->id]);
        }
    }

    private function usuario(string $email, string $nombre, string $rol, ?Restaurante $restaurante): User
    {
        return User::updateOrCreate(['email' => $email], [
            'name' => $nombre,
            'password' => 'password',
            'rol' => $rol,
            'restaurante_id' => $restaurante?->id,
            'activo' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, array<string, int>>  $areas  área => [código => capacidad]
     */
    private function mesas(Restaurante $restaurante, array $areas): void
    {
        $orden = 0;

        foreach ($areas as $nombreArea => $mesas) {
            $area = Area::updateOrCreate(
                ['restaurante_id' => $restaurante->id, 'nombre' => $nombreArea],
                ['activa' => true, 'orden' => $orden++],
            );

            foreach ($mesas as $codigo => $capacidad) {
                Mesa::updateOrCreate(
                    ['restaurante_id' => $restaurante->id, 'codigo' => $codigo],
                    ['area_id' => $area->id, 'capacidad' => $capacidad, 'estado' => 'disponible', 'activa' => true],
                );
            }
        }
    }
}
