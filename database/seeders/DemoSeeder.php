<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\CategoriaProducto;
use App\Models\GrupoOpcion;
use App\Models\HorarioAtencion;
use App\Models\Mesa;
use App\Models\MetodoPago;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\ProductoVariante;
use App\Models\Restaurante;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración: tres restaurantes con sus usuarios, mesas y carta, para probar
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
            'activo' => true,
        ]);

        $this->usuario('superadmin@foora.test', 'Equipo FOORA', 'superadmin', null);
        $this->usuario('admin@foora.test', 'Rosa Quispe', 'admin', $buenSabor);
        $this->usuario('cajero@foora.test', 'Miguel Torres', 'cajero', $buenSabor);
        $this->usuario('admin@labahia.test', 'Carla Mendoza', 'admin', $laBahia);
        $this->usuario('admin@chifa.test', 'Andrés Chang', 'admin', $dragonDorado);

        // De lunes a domingo; Dragón Dorado cierra de madrugada, y La Bahía no abre los lunes.
        $this->horarios($buenSabor, array_fill(1, 7, ['12:00', '22:00']));
        $this->horarios($laBahia, [1 => null, ...array_fill(2, 6, ['10:00', '16:00'])]);
        $this->horarios($dragonDorado, array_fill(1, 7, ['11:00', '02:00']));

        $this->metodosPago($buenSabor, ['efectivo', 'yape', 'plin', 'tarjeta']);
        $this->metodosPago($laBahia, ['efectivo', 'yape']);
        $this->metodosPago($dragonDorado, ['efectivo', 'yape', 'plin']);

        $this->cartaBuenSabor($buenSabor);
        $this->cartaLaBahia($laBahia);
        $this->cartaDragonDorado($dragonDorado);

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

        $this->command?->info('Demo lista. Usuarios: superadmin@foora.test, admin@foora.test, cajero@foora.test, admin@labahia.test, admin@chifa.test (contraseña: password).');
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
    private function metodosPago(Restaurante $restaurante, array $codigos): void
    {
        $restaurante->metodosPago()->sync(
            MetodoPago::whereIn('codigo', $codigos)->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['activo' => true]]),
        );
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
