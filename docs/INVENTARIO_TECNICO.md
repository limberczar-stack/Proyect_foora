# FOORA: inventario técnico del salón (fases A, B1 y B2)

> **Qué es este archivo.** El mapa de lo que existe en el código para el módulo Mesas: migraciones, tablas, modelos, servicios, rutas, permisos, vistas, JavaScript y pruebas. Se redactó leyendo el código y el esquema de MySQL al 15/11/2026. La lógica de producto está en `docs/FOORA_v1.1_salon_y_ventas.md` (secciones 3, 4, 7 y 8); las convenciones generales, en `CLAUDE.md`.
>
> **Estado.** Construido: fase A (áreas y mesas), B1 (cuentas) y B2 (pedidos de salón). **Pendiente:** Caja y ventas (fase C), Comprobantes (fase D), Reportes (E) y SUNAT (F).

---

## 1. Activación

| Pieza | Dónde | Qué hace |
|---|---|---|
| Interruptor global | `config/foora.php` → `foora.modulos.mesas` (`true`) | El módulo existe en la plataforma |
| Interruptor por local | `restaurantes.modulos` (JSON), `Restaurante::tieneModulo()` | El superadmin lo enciende en cada local |
| Regla única | `App\Support\Modulos::activoPara('mesas', $restaurante)` | Exige las dos cosas y que exista la ruta (`Modulos::RUTAS`) |
| Módulos que se encienden por local | `Modulos::POR_LOCAL` | Hoy solo `mesas` ("Mesas y salón"); cubre áreas, mesas, cuentas y rondas |
| Rutas | Middleware `modulo:mesas` (`EnsureModuloActivo`) | 404 si el módulo está apagado |
| Superadmin | `PUT panel/superadmin/restaurantes/{restaurante}/modulos` (`RestauranteController::modulos`, `ModulosRequest`) | Enciende o apaga; queda en `actividad_log` con el módulo `suscripciones` |

`modulos` no está en `$fillable`: el local no puede encenderse un módulo desde su configuración.

---

## 2. Migraciones

Todas nuevas, sin editar las anteriores. Cada una sube, baja y vuelve a subir en MySQL.

| Archivo | Fase | Cambio |
|---|---|---|
| `2026_11_11_045741_add_modulos_to_restaurantes_table` | A | `restaurantes.modulos` JSON nullable |
| `2026_11_11_045743_add_orden_and_codigo_activo_to_mesas_table` | A | `mesas.orden`; `codigo` a 20 caracteres; columna generada `codigo_activo`; índice `uq_mesa_activa` en lugar de `uq_mesa` |
| `2026_11_15_035139_create_cuentas_table` | B1 | Tabla `cuentas` |
| `2026_11_15_035140_add_cuenta_to_ocupaciones_mesa_table` | B1 | `ocupaciones_mesa.cuenta_id`; `personas` y `consumo_total` nullable; columna generada `mesa_abierta_id` con índice único `uq_ocu_mesa_abierta` |
| `2026_11_15_173600_add_salon_to_pedidos_table` | B2 | `nombre_cliente`, `telefono_cliente` y `metodo_pago_id` nullable; `cuenta_id`, `ronda`, índice `uq_ped_ronda`. El `down()` se niega a correr si hay pedidos sin esos datos |
| `2026_11_15_173601_add_anulacion_to_pedido_items_table` | B2 | `anulado_at`, `anulado_por`, `motivo_anulacion` |
| `2026_11_15_173603_add_precuenta_at_to_cuentas_table` | B2 | `cuentas.precuenta_at` |
| `2026_11_15_173604_add_precuenta_pedida_to_alertas_tipo` | B2 | `alertas.tipo` + `precuenta_pedida`. Su `down()` pasa esas alertas a `otro`, no las borra |

---

## 3. Tablas (esquema real)

### `areas` (sin cambios)
`restaurante_id`, `nombre`, `activa`, `orden`. Índice único `uq_area (restaurante_id, nombre)`.

### `mesas`
`restaurante_id`, `area_id` (FK con cascada), `codigo` varchar(20), `capacidad`, `orden`, `estado` (`disponible`, `ocupada`, `reservada`, `fuera_servicio`), `pos_x`, `pos_y`, `activa`, `codigo_activo` (generada).

- **`uq_mesa_activa (restaurante_id, codigo_activo)`:** el código es único solo entre las activas, porque `codigo_activo` vale NULL en las desactivadas.
- **Por la cascada de `area_id`**, un área con mesas (también desactivadas) no se elimina.

### `cuentas`
| Columna | Notas |
|---|---|
| `numero` | Único por local (`uq_cuenta_num`); `max + 1` con el local bloqueado |
| `estado` | `abierta`, `precuenta`, `cerrada`, `anulada`, `fusionada` |
| `version` | Sube con cada operación salvo las rondas; base del 409 |
| `mesero_id` | Quien la abrió o a quien se transfirió |
| `comensales` | 1 a 99 |
| `abierta_at`, `precuenta_at`, `cerrada_at`, `cerrada_por` | |
| `cierre_tipo` | `externo` (construido), `venta` (pendiente) |
| `fusionada_en_id` | FK a `cuentas` |
| `nota` | |
| `anulada_at`, `anulada_por`, `motivo_anulacion` | |

Índice `idx_cuenta_estado (restaurante_id, estado)`.

### `ocupaciones_mesa` ("las mesas de una cuenta")
`mesa_id`, `cuenta_id` (nullable, FK sin cascada), `ingreso_at`, `salida_at`, `mesa_abierta_id` (generada: `mesa_id` mientras `salida_at` es nulo).

- **`uq_ocu_mesa_abierta`:** como máximo una ocupación abierta por mesa.
- **Columnas sin uso con cuentas:** `reserva_id`, `pedido_id`, `mesero_id`, `personas` y `consumo_total`.

### `pedidos` (columnas que usa el salón)
| Columna | En salón |
|---|---|
| `tipo` / `origen` | `salon` / `local` |
| `cuenta_id`, `ronda` | Únicos juntos (`uq_ped_ronda`); NULL en delivery y recojo |
| `creado_por` | Quien envió la ronda (no hay `mesero_id`) |
| `nombre_cliente`, `telefono_cliente`, `metodo_pago_id` | NULL (la vitrina los sigue exigiendo) |
| `mesa_id` | NULL: las mesas salen de la cuenta |
| `numero` | Misma secuencia que la vitrina (`uq_pedido_num`) |
| `idempotency_key` | `uq_ped_idempotencia`, compartido con la vitrina |
| `estado_pago` | `pendiente` hasta cerrar la cuenta, luego `pagado` |
| `cancelacion_motivo` / `cancelacion_detalle` | Rondas y cuentas anuladas: `otro` y el motivo escrito |

### `pedido_items`
`anulado_at`, `anulado_por` (FK a users), `motivo_anulacion`. La línea anulada no se borra y no cuenta en el total del pedido.

### Otros
- **`alertas.tipo`:** + `precuenta_pedida`, con `referencia_tipo = 'cuenta'`.
- **`actividad_log.modulo`:** sin cambios. El salón usa `mesas`; el interruptor del superadmin, `suscripciones`.
- **Morph map** (`AppServiceProvider`): + `area` y `cuenta`.

---

## 4. Modelos

| Modelo | Qué agrega |
|---|---|
| `Area` | `scopeOrdenadas()`, `mesas()` |
| `Mesa` | `ESTADOS`, `ESTADOS_MANUALES` (`disponible`, `fuera_servicio`), `CAPACIDAD_MINIMA/MAXIMA`, `CODIGO_MAXIMO`, `scopeOrdenadas()`, `scopeActivas()`, `ocupacionAbierta()` |
| `Cuenta` (nuevo, con factory) | `ESTADOS_ABIERTOS`, `COMENSALES_MAXIMO`, `scopeAbiertas()`, `estaAbierta()`, `tiempoAbierta()`, `minutosAbierta()`, `mesasTexto()` ("Mesa 4 + 5"), `pedidos()` (por ronda), `ocupaciones()`, `ocupacionesAbiertas()` |
| `OcupacionMesa` | `cuenta()` |
| `Pedido` | `esSalon()`, `tipoCorto()`, `etiqueta()` ("Mesa 4 + 5 · Ronda 2 · Luis"), `cuenta()`, `RELACIONES_SALON` (cargar en listados para no hacer una consulta por pedido) |
| `PedidoItem` | `estaAnulada()`, `scopeVigentes()`, `anuladoPor()` |
| `Restaurante` | `tieneModulo()`; factory `conModulos('mesas')` |

`PedidoFactory::salon($cuenta, $ronda)` crea una ronda suelta para pruebas.

---

## 5. Servicios (`app/Services`)

Todos los que escriben abren una transacción y bloquean la fila del restaurante (`lockForUpdate`). En `GestionarCuentas` y `CrearPedidoSalon`, `bloquearRestaurante()` es `protected` solo para que los controles negativos de `tests/Concurrencia/` corran el mismo código sin el bloqueo.

| Servicio | Responsabilidad |
|---|---|
| `GestionarMesas` | Áreas y mesas: crear, editar, ordenar, activar o desactivar, eliminar área vacía, "Agregar varias" con prefijo. Aplica `planes.max_mesas`. Código único entre activas (422 si el índice salta). Una mesa ocupada no se pone fuera de servicio ni se desactiva; un área con mesas ocupadas no se desactiva |
| `GestionarCuentas` | Abrir (1 a 10 mesas), agregar, quitar y mover mesa, fusionar (mueve mesas y pedidos y renumera rondas), transferir, cerrar "Cobrado en otro sistema" (con total en céntimos y confirmación de rondas sin servir), anular (cancela sus pedidos). `totalCentimos()`, `mesasInconsistentes()`, `abiertasDe()` |
| `CrearPedidoSalon` | Envía una ronda: `CalculadorLineaPedido`, idempotencia, correlativo compartido, `en_preparacion`, sin `pedido_nuevo`. Devuelve una cuenta en precuenta a abierta y resuelve su alerta (`resolverAlertaPrecuenta()`) |
| `GestionarPedidoSalon` | Servir (vía `GestionarPedido`), anular línea (recalcula; si no quedan líneas cancela el pedido), cancelar ronda sin servir, pedir precuenta (alerta `precuenta_pedida`) |
| `PlanoMesas` | Datos del plano y de su actualización en vivo, con un número fijo de consultas |
| `ResumenInicio` | Tarjeta "Mesas ocupadas"; "Ventas de hoy" suma las rondas de cuentas cerradas hoy |
| `GestionarPedido` | Salón: servir no cobra ni exige pago verificado |
| `CrearPedido` | Sin cambio de lógica; `guardarItems()` ahora es público y estático para `CrearPedidoSalon` |
| `LineaPedidoCalculada` | `paraNavegador()`: formato de línea compartido por la vitrina y el borrador del salón |

Excepción `App\Exceptions\RondasSinServir`: es el 409 de cerrar con rondas sin servir, y lleva la lista de esas rondas.

---

## 6. Rutas

Todas bajo `auth`, `panel`, `local.operativo` y `modulo:mesas`, con el prefijo `panel/mesas` y el nombre `panel.mesas.*`.

| Método | URI | Nombre | Controlador |
|---|---|---|---|
| GET | `/` | `index` | `MesaController@index` (plano; con `?editar=1` el admin gestiona el salón) |
| POST | `/` | `store` | `MesaController@store` |
| POST | `varias` | `varias` | `MesaController@storeVarias` |
| PUT | `{mesa}` | `update` | `MesaController@update` |
| PATCH | `{mesa}/estado` · `activa` · `mover` | `estado` · `activa` · `mover` | `MesaController` |
| POST | `areas` | `areas.store` | `AreaController@store` |
| PUT · DELETE | `areas/{area}` | `areas.update` · `areas.destroy` | `AreaController` |
| PATCH | `areas/{area}/activa` · `mover` | `areas.activa` · `areas.mover` | `AreaController` |
| GET | `plano` | `plano` | `CuentaController@plano` (JSON para el sondeo cada 10 s) |
| POST | `cuentas` | `cuentas.store` | `CuentaController@store` |
| GET | `cuentas/{cuenta}` | `cuentas.show` | `CuentaController@show` (fragmento por AJAX o página) |
| POST | `cuentas/{cuenta}/mesas` | `cuentas.mesas.agregar` | `CuentaController@agregarMesa` |
| PATCH | `cuentas/{cuenta}/mesas/quitar` · `mesas/mover` | `cuentas.mesas.quitar` · `cuentas.mesas.mover` | `CuentaController` |
| PATCH | `cuentas/{cuenta}/fusionar` · `mesero` | `cuentas.fusionar` · `cuentas.transferir` | `CuentaController` |
| PATCH | `cuentas/{cuenta}/cerrar` · `anular` | `cuentas.cerrar` · `cuentas.anular` | `CuentaController` |
| GET | `cuentas/{cuenta}/pedido` | `cuentas.pedido.create` | `PedidoSalonController@create` (toma de pedido) |
| POST | `cuentas/{cuenta}/pedido/validar` | `cuentas.pedido.validar` | `PedidoSalonController@validar` (precios del borrador; 120 por minuto) |
| POST | `cuentas/{cuenta}/pedido` | `cuentas.pedido.store` | `PedidoSalonController@store` (envía la ronda) |
| PATCH | `cuentas/{cuenta}/pedidos/{pedido}/servir` · `cancelar` | `cuentas.pedidos.servir` · `cuentas.pedidos.cancelar` | `PedidoSalonController` |
| PATCH | `cuentas/{cuenta}/lineas/{item}/anular` | `cuentas.lineas.anular` | `PedidoSalonController@anularLinea` |
| PATCH · GET | `cuentas/{cuenta}/precuenta` | `cuentas.precuenta` · `cuentas.precuenta.imprimir` | `PedidoSalonController` |

**Fuera de operación** (`EnsureLocalOperativo::ESCRITURAS_PERMITIDAS`): del salón solo se permiten `cuentas.cerrar`, `cuentas.anular` y `cuentas.pedidos.servir`.

**Fuera del prefijo:** `panel.pedidos.*` acepta `tipo=salon`, y `vitrina.pedido.*` responde 404 para pedidos de salón.

---

## 7. Permisos

| Policy | Habilidades |
|---|---|
| `MesaPolicy` | `viewAny` (admin, cajero, mesero), `gestionar` y `update` (admin) |
| `AreaPolicy` | `update` y `delete` (admin) |
| `CuentaPolicy` | `atender`, `update`, `servir` y `pedirPrecuenta` (admin, cajero, mesero); `corregirRondas` y `cerrar` (admin, cajero); `anular` (admin y cajero; el mesero, solo una cuenta suya sin pedidos vivos) |
| `PedidoPolicy` | `verMontos` (admin, cajero: la cocina nunca recibe montos); `verContacto` y `revisarPago` son falsos en salón; `cambiarEstado` deja al mesero solo `listo → entregado` en salón |

- **Regla del mesero revisada dos veces:** `GestionarCuentas::anular` vuelve a comprobarla con el local bloqueado.
- **Superadmin:** no entra al salón de un local (404).
- **Cocina y repartidor:** 403.

---

## 8. Vistas y componentes

| Archivo | Qué es |
|---|---|
| `components/mesa.blade.php` (`x-mesa`) | Mesa por estado (los cuatro), con `alerta` (más de 6 h) y `as` (`button` o `a`) |
| `panel/mesas/index.blade.php` | Plano para atender, con pestañas por área |
| `panel/mesas/_mesa.blade.php` | Una mesa del plano; la usan la página y el JSON de `plano` (deben pintar igual) |
| `panel/mesas/gestion.blade.php`, `_modales.blade.php` | "Editar salón" (admin) |
| `panel/mesas/_cuenta.blade.php`, `cuenta.blade.php` | Detalle de una cuenta: hoja inferior o página |
| `panel/mesas/_rondas.blade.php` | Rondas, líneas, total, servir, anular línea y cancelar ronda |
| `panel/mesas/pedido.blade.php` | Toma de pedido del mesero |
| `panel/mesas/precuenta.blade.php` | Ticket de 80 mm, sin el layout del panel, rotulado "Precuenta. No es comprobante de pago" |
| `vitrina/_carta.blade.php` | Búsqueda, chips y secciones, compartidos con la vitrina (el HTML público quedó idéntico) |
| `vitrina/_productos.blade.php`, `_hoja_producto.blade.php` | Reutilizados en la toma de pedido |
| `panel/pedidos/_lista.blade.php`, `_detalle.blade.php` | Filtro "Salón"; sin contacto ni pago en salón; sin montos para la cocina |
| `panel/inicio.blade.php` | Tarjeta "Mesas ocupadas"; leyenda de "Ventas de hoy" con entregas y cuentas de salón |
| `panel/superadmin/restaurantes/show.blade.php` | Tarjeta "Módulos" |

---

## 9. JavaScript

| Archivo | Entrada | Qué hace |
|---|---|---|
| `resources/js/carta/util.js`, `busqueda.js`, `hojas.js`, `detalle.js` | Compartidos | Soles, `localStorage` seguro, saneo de líneas, búsqueda sin tildes, chips, hoja inferior con arrastre, detalle de producto con sus reglas |
| `resources/js/vitrina.js` | `vitrina.js` (pública) | Carrito, validación, finalizar, "Mi pedido". Claves sin cambio: `foora.carrito.v1.{slug}` y `foora.pedido.v1.{slug}` |
| `resources/js/mesas.js` | `app.js` (panel) | Modales de "Editar salón" |
| `resources/js/salon.js` | `app.js` | Plano en vivo (cada 10 s, con pausa en pestaña oculta), abrir cuenta, hoja de detalle, envío de acciones con manejo del 409 |
| `resources/js/salon-pedido.js` | `app.js` | Toma de pedido: borrador `foora.salon.v1.{cuentaId}` (ids, cantidades, opciones, nota; nunca precios), total del servidor, clave de idempotencia por borrador, marca de líneas rechazadas |

Vite separa `carta/` en un trozo compartido: la vitrina carga jQuery y ese trozo, nunca `app.js`.

---

## 10. Datos demo (`DemoSeeder`)

- **Mesas:** solo El Buen Sabor tiene el módulo encendido, con Salón principal (1–8; la 6 fuera de servicio) y Terraza (T1–T4).
- **Cuenta "4 + 5"** (mesero, 6 comensales): tres rondas, servida, lista y en preparación. Una línea lleva observación.
- **Cuenta "T2"** (admin): una ronda servida y la precuenta pedida, con su alerta.
- **Numeración:** las rondas siguen la secuencia de los 12 pedidos demo de la vitrina (13 a 16).
- **Volver a sembrar** reinicia los pedidos y las cuentas demo (borra y crea de nuevo). Bloqueado en producción.

---

## 11. Pruebas

**Feature** (PHPUnit, SQLite en memoria):

| Archivo | Pruebas | Cubre |
|---|---|---|
| `tests/Feature/Mesas/MesasAccesoTest.php` | 16 | Roles, 404 entre locales, módulo apagado, inicio del mesero |
| `tests/Feature/Mesas/GestionMesasTest.php` | 22 | Mesas, código único, carrera con el índice, estados manuales, "Agregar varias" |
| `tests/Feature/Mesas/AreasTest.php` | 10 | Áreas |
| `tests/Feature/Mesas/LimiteMesasTest.php` | 8 | `max_mesas` |
| `tests/Feature/Mesas/MesasSoloLecturaTest.php` | 3 | Fuera de operación (gestión) |
| `tests/Feature/Mesas/CuentasTest.php` | 30 | Cada operación de cuenta, invariante, carrera con el índice, reglas cruzadas |
| `tests/Feature/Mesas/CuentasPermisosTest.php` | 10 | Permisos de cuentas, 404, módulo apagado, fuera de operación |
| `tests/Feature/Mesas/PlanoMesasTest.php` | 15 | Plano, consultas acotadas, 6 horas, detalle, tarjeta de inicio, aviso al desactivar usuarios |
| `tests/Feature/Mesas/RondasSalonTest.php` | 20 | Envío de rondas, límites, idempotencia, línea rechazada, precuenta y ronda, XSS |
| `tests/Feature/Mesas/GestionRondasTest.php` | 33 | Servir, anular línea, cancelar ronda, precuenta, cerrar, anular, fusionar, ventas del día, consultas acotadas |
| `tests/Feature/Panel/PedidoSalonEnPanelTest.php` | 13 | Pedido de salón en lista, detalle e inicio; cocina sin montos; seguimiento público |
| `tests/Feature/Vitrina/PedidoPublicoCamposTest.php` | 4 | La vitrina sigue exigiendo nombre, teléfono y método de pago |
| `tests/Feature/Superadmin/ModulosRestauranteTest.php` | 9 | Interruptor de módulos |

Con los casos de los data providers, estos archivos corren 231 pruebas. `MotivosInternosTest` incluye `panel.mesas.index` con el módulo encendido.

**Concurrencia** (`tests/Concurrencia/`, MySQL real, fuera de phpunit, solo en la base `foora_concurrencia`; ver su `README.md`):

| Script | Escenarios |
|---|---|
| `limite_mesas.php` | Crear, agregar varias y reactivar al límite del plan; control sin bloqueo |
| `cuentas.php` | Misma mesa, mesas cruzadas, numeración de cuentas; controles sin bloqueo y sin índices |
| `pedidos_salon.php` | Misma ronda con la misma clave, ronda y cierre a la vez, numeración mixta vitrina y salón, fusión y agregar mesa a la vez; controles sin bloqueo y sin índices |
| `_arranque.php` | Arranque común: aborta si la base no es `foora_concurrencia` y la reconstruye antes de cada corrida |

---

## 12. Pendiente

- **Caja y ventas (fase C):**
  - Tablas `caja_sesiones`, `caja_movimientos`, `ventas` y `venta_pagos`.
  - Cierre de cuenta con `cierre_tipo = venta`.
  - Pago mixto, descuentos, arqueo.
  - Concurrencia de dos cajeros cobrando la misma cuenta.
  - Decidir si "Ventas de hoy" sale de `ventas`.
- **Comprobantes (fase D):**
  - Tablas de series, comprobantes, líneas y clientes de facturación.
  - Datos fiscales del local.
  - Ticket del comprobante y enlace público.
  - Concurrencia de correlativos de serie.
- **No construido del diseño:**
  - Ajuste "el mesero ve solo sus cuentas".
  - Flujo para que el mesero solicite anulaciones.
  - Alerta `pedido_listo_salon` (el aviso es la marca del plano).
  - Estado Reservada en el plano (llega con Reservas).
  - Regla "no fusionar si alguna cuenta ya tiene venta" (llega con la Caja).
- **Sin verificar en un navegador:** el comportamiento de `salon.js` y `salon-pedido.js`, el diseño a 390 px y la impresión real en 80 mm.
