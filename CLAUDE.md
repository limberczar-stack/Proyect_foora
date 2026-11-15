# FOORA: estado del proyecto

SaaS multi-tenant para restaurantes de Perú (suscripción mensual por local, sin comisión). Laravel 13, PHP 8.3, MySQL 8.4, Blade, Tailwind 4, jQuery y Vite. Alcance de producto completo: `docs/alcance.md`. Interfaz en español de Perú, montos en S/, móvil primero.

## Módulos terminados

- **Login y acceso:** roles superadmin, admin, cajero, mesero, cocina y repartidor; contraseña temporal obliga a pasar por Mi cuenta (`EnsurePanelAccess`).
- **Inicio del panel** (`InicioController`, `ResumenInicio`): admin y cajero ven el resumen real del día, pedidos recientes y alertas (y la tarjeta "Mesas ocupadas x/total" si el local tiene Mesas); solo el admin ve Primeros pasos. Cocina va a sus pedidos. El mesero va a `panel/mesas` si su local tiene Mesas; si no, él y el repartidor reciben una página (200) que les dice que aún no tienen módulos. Las reglas de cada tarjeta están en `ResumenInicio`.
- **Carta:** categorías, productos, variantes, grupos de opciones, combos y disponibilidad.
- **Vitrina pública** (`/{slug}`): carta, carrito, pedido sin login, seguimiento por código.
- **Panel de pedidos:** estados, revisión de pagos Yape/Plin, actualización en vivo y control de concurrencia.
- **Configuración:** general, horario, pago, pedidos, notificaciones, usuarios, Mi cuenta y abrir/cerrar local.
- **Mesas (fase A de `docs/FOORA_v1.1_salon_y_ventas.md`):** áreas y mesas, "Agregar varias" con prefijo, límite `planes.max_mesas`. Solo el admin gestiona, en `panel/mesas?editar=1` ("Editar salón", vista `gestion.blade.php`); ahí ve también lo desactivado.
- **Cuentas de salón (fase B1):** `panel/mesas` es el plano para atender (admin, cajero y mesero; cocina y repartidor, 403). Tocar una mesa disponible abre una cuenta (se pueden unir hasta 10 mesas de cualquier área); tocar una ocupada abre su detalle con las acciones: agregar, quitar y mover mesa, unir con otra cuenta, transferir, cerrar ("Cobrado en otro sistema") y anular con motivo. Cerrar es de admin y cajero; anular también, y el mesero solo una cuenta suya. El plano se actualiza cada 10 s (`resources/js/salon.js`, `panel.mesas.plano`) y resalta las cuentas con más de 6 horas. Aún sin pedidos de salón, precuenta, ventas ni comprobantes.
- **Superadmin:** alta de locales, planes, pagos manuales, anulación, cancelación, suspensión, notas internas, módulos por local y resumen.
- **Suscripción del local:** Mi suscripción (solo lectura), avisos con contacto de FOORA y modo solo lectura fuera de operación.
- **Auditoría:** `actividad_log` se escribe en todo cambio; aún no tiene pantalla.

## Arquitectura

- **Multi-tenant:** `App\Models\Concerns\BelongsToRestaurante` filtra por el `restaurante_id` del usuario con sesión. No filtra sin sesión, ni para superadmin.
- **Rutas públicas:** no hay sesión, así que el scope no protege. Filtra siempre explícito por `restaurante_id` y quita el scope a propósito (ver `CartaPublica`, `PedidoPublicoController`).
- **`Restaurante::operativo()`:** única regla de si el local opera (no suspendido, no cancelada, gracia sin pasar). Sale de las fechas, no depende del comando diario. No confundir con `abiertoAhora()`.
- **`EnsureLocalOperativo`** (`local.operativo`): fuera de operación solo deja escrituras de pedidos en curso, cerrar o anular cuentas de salón ya abiertas (no abrir ni modificarlas), abrir/cerrar y la propia cuenta.
- La vitrina y `CrearPedido` también revisan `operativo()`.
- **Módulos por local:** los de `Modulos::POR_LOCAL` (hoy solo `mesas`) exigen tres cosas: `foora.modulos`, que el superadmin lo encienda en el local (`restaurantes.modulos`, JSON; `Restaurante::tieneModulo()`) y que exista su ruta. Pregunta siempre con `Modulos::activoPara($modulo, $restaurante)`, no con `activo()` ni `tieneModulo()` solos. El middleware `modulo:mesas` (`EnsureModuloActivo`) responde 404 si está apagado. `modulos` no está en `$fillable`: solo lo cambia el superadmin (detalle del restaurante, auditado en `suscripciones`). La clave `mesas` cubrirá también la fase B (cuentas y mesero): **no crees otra clave**.

### Servicios clave (`app/Services`)

- `CrearPedido`: transacción con la fila del restaurante bloqueada (correlativo, idempotencia, límite por teléfono).
- `CalculadorLineaPedido`: valida y calcula en céntimos. Los precios salen de la BD, nunca del cliente.
- `GestionarPedido`: cambios de estado y de pago con la fila bloqueada; conflicto da 409. **No existe clase `TransicionPedido`:** las reglas están en `Pedido::TRANSICIONES` y `Pedido::puedePasarA()`, y las prueba `TransicionPedidoTest`.
- `SeguimientoPedido`: datos públicos del seguimiento; nunca incluye `cancelacion_detalle`.
- `CartaPublica`: carta y restaurante de la vitrina.
- `CrearRestaurante`: alta de local, admin y prueba gratis.
- `GestionarSuscripcion`: pagos, anulación, plan, cancelación y suspensión, siempre con `lockForUpdate`. La referencia no tiene índice único: **el bloqueo es lo que evita duplicados** (probado con procesos reales en MySQL).
- `GestionarMesas`: áreas y mesas, siempre con el restaurante bloqueado (`lockForUpdate`), igual que `GestionarUsuarios`.
  - `max_mesas` se aplica al crear, al agregar varias y al reactivar; cuenta las mesas activas aunque su área esté desactivada; límite nulo = sin tope.
  - El código es único solo entre las activas: columna generada `mesas.codigo_activo` + índice `uq_mesa_activa`. Se valida antes y, si el índice salta, se responde 422, nunca 500.
  - A mano solo se pasa entre `disponible` y `fuera_servicio` (`Mesa::ESTADOS_MANUALES`); `ocupada` la ponen las cuentas y `reservada` saldrá de las reservas, nunca de una petición.
  - Una mesa ocupada no se pone fuera de servicio ni se desactiva, y un área con mesas ocupadas no se desactiva.
  - Las mesas no se borran: se desactivan. Un área con mesas (también desactivadas) no se elimina: la FK las borraría en cascada.
  - Área desactivada: no aparece en el plano; el admin la ve atenuada al editar el salón.
- `GestionarCuentas`: cuentas de salón, cada operación en una transacción con el restaurante bloqueado (`lockForUpdate`).
  - **Invariante:** una mesa está `ocupada` si y solo si tiene una ocupación abierta (`ocupaciones_mesa.salida_at` nulo). Entrar o salir de una cuenta cambia `mesas.estado` en la misma transacción; no cambies ese estado por otra vía. `GestionarCuentas::mesasInconsistentes()` debe devolver siempre vacío.
  - `cuentas.version` sube con cada operación y quien actúa envía la que veía: si cambió, **409** (al fusionar se comparan las dos). Abrir sobre una mesa que ya no está libre también es 409, no 422.
  - `cuentas.numero` es correlativo por local sin huecos (`max + 1` con el local bloqueado); una cuenta anulada conserva su número.
  - Los índices `uq_ocu_mesa_abierta` (columna generada `mesa_abierta_id`) y `uq_cuenta_num` son la última defensa: si saltan, se responde 409, nunca 500. **El bloqueo es lo que da respuestas ordenadas y evita interbloqueos**; sin él los índices aguantan el dato pero se pierden aperturas legítimas (probado con procesos reales).
  - Nada se borra: cerrar, anular y fusionar dejan la cuenta y sus ocupaciones como historial. Mover y fusionar cierran la ocupación vieja y crean otra.
  - `ocupaciones_mesa` es "las mesas de una cuenta": `personas`, `mesero_id` y `consumo_total` no se usan (comensales y mesero viven en `cuentas`).
  - Tope de 10 mesas por cuenta (también al agregar y fusionar); al fusionar se suman los comensales (tope 99) y queda el mesero de la primera.
  - `bloquearRestaurante()` es `protected` solo para que el control negativo de `tests/Concurrencia/cuentas.php` ejecute el mismo código sin bloqueo.
  - Desactivar a un usuario con cuentas abiertas no se bloquea: `UsuarioController::estado` devuelve un `aviso` con el conteo.
- `PlanoMesas`: lo que pinta el plano y su actualización en vivo, con un número fijo de consultas (lo prueba `PlanoMesasTest`). `panel.mesas._mesa` debe pintar igual en la página y en `panel.mesas.plano`.
- `GestionarUsuarios`, `RegistrarActividad` (quita contraseñas y tokens de la auditoría).
- `App\Support\ContactoFoora` y `WhatsApp`: enlaces wa.me (solo celulares peruanos) y mailto.

### Datos internos de FOORA

El local y el público nunca ven motivos de suspensión, cancelación o anulación, ni notas internas:

- `$hidden`: `Restaurante::notas_internas`, `SuscripcionPago::motivo_anulacion`.
- `ActividadLog` tiene un global scope que oculta el módulo `suscripciones` a todo usuario de un local. La futura pantalla de Actividad queda protegida sin hacer nada; **no le quites ese scope**.
- `MotivosInternosTest` recorre todas las rutas GET `panel.*` sin parámetros, incluidas las nuevas.

## Convenciones

- Código de dominio y textos en español; nombres de pruebas en inglés (`test_...`).
- Pruebas en `tests/Feature/<Área>` con PHPUnit, SQLite en memoria y factories; las de fechas usan `Carbon::setTestNow`.
- Concurrencia: scripts en `tests/Concurrencia/` (fuera de phpunit), con procesos `php` reales contra MySQL y controles negativos que deben fallar. Comparten `_arranque.php`, que borra la base y aborta si no se llama `foora_concurrencia`. Ver su `README.md`.
- En pruebas de un módulo por local, crea el restaurante con `Restaurante::factory()->conModulos('mesas')`.
- Fechas de negocio en la zona del local: `Restaurante::fechaHoy()`.
- Respuestas AJAX: JSON con `mensaje`; sin JavaScript, redirección con `status` o `error`.
- JS con jQuery por módulo en `resources/js`; la vitrina tiene entrada propia para no cargar el JS del panel.
- Reusa los componentes de `resources/views/components` (`x-button`, `x-card`, `x-input`, `x-table`, etc.).
- Config propia en `config/foora.php`; lee `env()` solo en `config/`.
- Pagos y logs no se borran: se anula con una marca.

## Comandos

```bash
php artisan test --compact                    # toda la suite (no toca MySQL)
php artisan test --compact --filter=NombreTest
vendor/bin/pint --dirty --format agent        # después de cambiar PHP
npm run build
composer run dev
php artisan migrate
php artisan db:seed                           # rubros, planes y métodos de pago
php artisan db:seed --class=DemoSeeder        # datos demo; bloqueado en producción
php artisan foora:revisar-suscripciones       # programado 00:10 hora de Lima
php tests/Concurrencia/limite_mesas.php       # concurrencia de max_mesas; solo en la base foora_concurrencia
php tests/Concurrencia/cuentas.php            # concurrencia de cuentas de salón (tarda unos minutos)
```

Usuarios demo (contraseña `password`): `superadmin@foora.test`, `admin@foora.test`, `cajero@foora.test`, `cocina@foora.test`, `mesero@foora.test`, `admin@labahia.test`, `admin@chifa.test`. Estados de suscripción: `admin@prueba.test`, `admin@aldia.test`, `admin@gracia.test`, `admin@fuera.test`. En la demo solo El Buen Sabor (`@foora.test`) tiene Mesas encendido: Salón principal y Terraza, 12 mesas y dos cuentas abiertas (mesas 4 + 5 unidas, y T2).

Variables propias: `FOORA_CONTACTO_WHATSAPP` y `FOORA_CONTACTO_EMAIL`. Vacías, los avisos salen sin enlaces.

## Pendientes conocidos

- **Sin construir:** Alertas, Reservas, Promociones, Actividad y Ayuda. Están ocultos: cada uno se enciende en `foora.modulos` y aparece en el menú cuando además existe su ruta (`App\Support\Modulos::RUTAS`).
- **Salón (v1.1):** falta el resto de la fase B (tomar pedido, rondas, cocina, precuenta) y las siguientes (caja, comprobantes, reportes). En `cuentas` ya existen el estado `precuenta` y el cierre `venta`, pero nada llega a ellos todavía. Las cuentas abiertas por más de 6 horas solo se resaltan: no hay cierre automático. `x-mesa` está preparado para Reservada, que aún no se usa.
- **Mesero sin Mesas y repartidor:** aún no tienen módulos asignados; su inicio solo lo explica.
- **Configuración:** faltan Impresión, Integraciones y Seguridad. **Pedidos:** falta imprimir tickets.
- **App del cliente:** sin empezar (registro, favoritos, explorar).
- Las notificaciones de vencimiento son solo avisos en el panel: no salen por correo ni WhatsApp.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
