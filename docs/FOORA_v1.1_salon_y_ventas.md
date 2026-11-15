# FOORA v1.1: salón (mesero), caja y comprobantes manuales

> **Qué es este archivo.** Propuesta de diseño para ampliar FOORA v1 con tres módulos: **Salón** (mesas y mesero), **Caja y ventas**, y **Comprobantes manuales** (boleta, factura, nota de venta, sin conexión a SUNAT). Complementa `FOORA_v1_documentacion.md` y respeta sus reglas (secciones 6, 8 y 10).
>
> **Límites.** Se redactó a partir de la documentación de v1, no del código. Las tablas `areas`, `mesas` y `ocupaciones_mesa` ya existen, pero no conozco sus columnas: antes de migrar, comparar con `foora_schema.sql`. Los datos tributarios (sección 6) deben confirmarse con el contador de cada local.

---

## 1. El problema de fondo: "ya tienen otro sistema de ventas"

Si FOORA se presenta como reemplazo del sistema de ventas, compite contra algo que el restaurante ya paga, ya conoce y que ya emite comprobantes electrónicos. Es una venta difícil. La propuesta es que **FOORA nunca obligue a cambiar de sistema**: cada módulo se activa por separado y los tres niveles funcionan solos.

| Nivel | Qué usa el local | Qué pasa con su sistema actual |
|---|---|---|
| **1. Canal online** (v1 actual) | Vitrina, pedidos delivery y recojo | Lo sigue usando igual. FOORA solo le trae pedidos |
| **2. Salón** | Mesero toma pedidos en el celular, cocina los ve, precuenta | Lo sigue usando para cobrar y emitir. El cajero lee el total en la precuenta de FOORA y lo registra allá |
| **3. Caja y comprobantes** | Cobro, caja diaria, boleta y factura desde FOORA | Lo reemplaza (o lo deja solo para el envío a SUNAT hasta que FOORA lo integre) |

Consecuencias de diseño:

1. **Módulos por restaurante, no globales.** Hoy `config/foora.php` enciende módulos para toda la plataforma. Se necesita un interruptor por local (columna JSON `restaurantes.modulos` con `salon`, `caja`, `comprobantes`), que el superadmin controla y que puede atarse al plan.
2. **El Salón no depende de la Caja.** Una cuenta de mesa se puede cerrar con "Cobrado en otro sistema" sin generar venta ni comprobante en FOORA.
3. **Exportación diaria.** Reporte de ventas del día en CSV/Excel (por producto y por método de pago), para que el local que cobra en otro sistema pueda cuadrar.
4. **Encaje con los planes** (sugerencia a validar): Básico = nivel 1, Pro = niveles 1 y 2, Premium = los tres.

**Recomendación de orden:** construir primero el Salón. Da valor visible (el mesero deja la libreta, cocina recibe el pedido al instante) sin pedirle al local que abandone nada. Antes de construir la Caja, preguntar a los locales del piloto qué sistema usan y qué les molesta de él.

---

## 2. Alcance

### Incluido
- Gestión de áreas y mesas (interfaz sobre las tablas existentes).
- Módulo del mesero: abrir mesa, **unir mesas**, tomar pedido desde la carta, enviar a cocina por rondas, ver la cuenta, pedir precuenta.
- Cocina: ve los pedidos de salón con mesa y mesero.
- Caja: apertura y cierre con arqueo, cobro de cuentas de mesa y de pedidos delivery o recojo, pago mixto, vuelto.
- Comprobantes manuales: boleta, factura y nota de venta, con series y correlativos propios, anulación con motivo, impresión en ticket de 80 mm.
- Reporte de ventas del día.

### No incluido (queda para después)
- Envío a SUNAT (XML, firma, CDR, resumen diario, notas de crédito electrónicas).
- Dividir la cuenta por comensal o por productos (el modelo ya lo permite, sección 5.1).
- Inventario, recetas y costos.
- Propinas y recargo al consumo.
- Impresión directa a impresora térmica por red (en esta versión se imprime con el diálogo del navegador).
- Reservas (siguen en la hoja de ruta; el Salón les deja listas las mesas).

---

## 3. Roles y permisos nuevos

| Acción | admin | cajero | mesero | cocina |
|---|---|---|---|---|
| Crear y editar áreas y mesas | Sí | No | No | No |
| Ver mapa de mesas | Sí | Sí | Sí | No |
| Abrir cuenta, unir mesas, tomar pedido | Sí | Sí | Sí | No |
| Mover o separar mesas de una cuenta | Sí | Sí | Sí (solo sus cuentas) | No |
| Anular un producto ya enviado a cocina | Sí | Sí, con motivo | No (lo solicita) | No |
| Pedir precuenta | Sí | Sí | Sí | No |
| Marcar pedido de salón como listo | Sí | Sí | No | Sí |
| Marcar pedido de salón como servido | Sí | Sí | Sí | No |
| Abrir y cerrar caja, cobrar | Sí | Sí | No | No |
| Emitir comprobante | Sí | Sí | No | No |
| Anular venta o comprobante | Sí | Configurable | No | No |
| Configurar series e impuestos | Sí | No | No | No |

- El inicio del **mesero** redirige al mapa de mesas (hoy muestra "cuenta sin módulos habilitados").
- Ajuste por local: el mesero ve **todas** las cuentas o **solo las suyas** (por defecto, todas; en locales chicos se cubren entre ellos).
- Cocina sigue sin ver montos ni datos del cliente.

---

## 4. Módulo Salón (mesero)

### 4.1 Concepto central: la **cuenta**

Una **cuenta** es una atención abierta en una o varias mesas. Es el objeto que resuelve "dos mesas juntas": la cuenta tiene muchas mesas, y los pedidos cuelgan de la cuenta, no de la mesa.

- Una mesa solo puede estar en **una cuenta abierta** a la vez.
- Cada envío a cocina (**ronda**) es un `pedido` normal con `tipo = salon`, `origen = local` y `cuenta_id`. Así se reutilizan `CalculadorLineaPedido`, las copias de nombres y precios, el historial y la pantalla de cocina.
- El total de la cuenta es la suma de sus pedidos no cancelados, **calculada siempre en el servidor**.

### 4.2 Flujo del mesero (pensado para celular, 390 px)

1. **Mapa de mesas** por área (pestañas: Salón principal, Terraza…). Cada mesa muestra número, capacidad y estado por color y texto: *Libre*, *Ocupada* (con tiempo transcurrido y monto), *Precuenta pedida*. Las mesas unidas muestran la etiqueta de su grupo ("4 + 5").
2. **Abrir cuenta:** toca una mesa libre → número de comensales → opción **"Unir con otras mesas"** (selección múltiple entre las mesas libres, de cualquier área) → Abrir.
3. **Tomar pedido:** la misma carta de la vitrina (búsqueda, chips de categoría, variantes, opciones con sus reglas, cantidad, observación por línea). El borrador vive en el navegador solo con ids y cantidades, igual que el carrito público.
4. **Enviar a cocina:** crea la ronda. Con clave de idempotencia, porque el wifi de los locales falla y el mesero va a tocar dos veces.
5. **Ver cuenta:** rondas enviadas con su estado (en preparación, listo, servido), total acumulado, botón "Agregar más".
6. **Pedir precuenta:** la cuenta pasa a `precuenta`, se avisa a caja (alerta `precuenta_pedida`) y se puede imprimir o mostrar en pantalla.
7. **Cierre:** lo hace caja al cobrar (sección 5) o, si el local no usa la Caja de FOORA, con "Cobrado en otro sistema". Al cerrar, todas las mesas de la cuenta quedan libres.

### 4.3 Operaciones sobre mesas

| Operación | Regla |
|---|---|
| **Unir al abrir** | Todas las mesas elegidas deben estar libres |
| **Agregar mesa a una cuenta abierta** | La mesa nueva debe estar libre |
| **Fusionar dos cuentas abiertas** | Los pedidos de la segunda pasan a la primera; la segunda queda `fusionada` (no se borra). No se permite si alguna ya tiene venta registrada |
| **Quitar una mesa** | La cuenta debe conservar al menos una mesa |
| **Mover de mesa** | Cambia las mesas, los pedidos no se tocan |
| **Transferir a otro mesero** | Cambia `mesero_id`, queda en el historial |

### 4.4 Estados de los pedidos de salón

- Entran directo a **`en_preparacion`** (no pasan por `nuevo`: no hay nada que aceptar ni pago que verificar). No suenan como "pedido nuevo" en el panel del cajero; sí aparecen en cocina.
- `en_preparacion → listo` (cocina) `→ entregado` con la etiqueta **"Servido"** (mesero). `en_camino` no aplica.
- Cuando un pedido pasa a `listo`, el mesero recibe aviso en su mapa de mesas (consulta periódica, igual que el panel de pedidos).
- El **pago no se maneja por pedido** en salón: se maneja por cuenta. Los pedidos de salón quedan con pago `pendiente` y pasan a `pagado` al cobrarse la cuenta. La regla "no se entrega con pago por verificar" no les aplica.
- **Anular un producto ya enviado:** exige motivo y rol admin o cajero. Se cancela la línea (marca, no DELETE) y se recalcula el total. Antes de enviar, el mesero edita su borrador libremente.

### 4.5 Impacto en lo existente

- **Panel de pedidos:** nuevo filtro de tipo "Salón". El badge de "nuevos" no cambia.
- **Cocina:** cada tarjeta de salón muestra "Mesa 4 + 5 · Ronda 2 · Juan".
- **Inicio:** "Pedidos de hoy" cuenta rondas; conviene añadir "Mesas ocupadas" y, cuando exista la Caja, calcular "Ventas de hoy" desde `ventas` en vez de pedidos entregados (decidirlo una sola vez para no tener dos cifras distintas).
- **Límite del plan:** aplicar por fin `planes.max_mesas` al crear o reactivar mesas.
- **Local no operativo o suspendido:** se pueden cerrar y cobrar las cuentas abiertas, no abrir nuevas (misma lógica de solo lectura de la sección 7.8 de v1).

---

## 5. Módulo Caja y ventas

### 5.1 Venta

Una **venta** es el registro del cobro. Se origina en:
- una **cuenta de salón**, o
- un **pedido delivery o recojo** (al entregarlo), o
- una **venta rápida de mostrador** (el cajero arma el pedido y cobra en el acto; crea un pedido `tipo = recojo`, `origen = local`).

Reglas:
- **Pago mixto:** una venta tiene uno o más pagos (por ejemplo S/ 50 en efectivo y S/ 30 en Yape). La suma de pagos debe igualar el total. En efectivo se registra lo recibido y el vuelto.
- **Descuento:** monto o porcentaje, con motivo obligatorio, solo admin o cajero. Guardar quién lo aplicó. (Usa `pedidos.descuento`, hoy siempre en 0, o un descuento a nivel de venta: elegir uno solo; recomiendo a nivel de venta.)
- **Cortesía:** venta con total 0 y motivo. Genera nota de venta interna, no boleta.
- **Anulación:** marca con motivo, usuario y fecha; nunca DELETE. Anular una venta anula su comprobante y reabre la cuenta o el pedido para volver a cobrar.
- `ventas.cuenta_id` **no es único**, para permitir más adelante dividir la cuenta en varias ventas.

### 5.2 Caja (sesión de caja)

- **Apertura:** monto inicial en efectivo. Sin caja abierta no se puede cobrar.
- **Movimientos:** ingresos y egresos manuales con motivo (compra de gas, propina al repartidor, retiro del dueño).
- **Cierre con arqueo:** el sistema muestra lo esperado por método de pago; el cajero ingresa el efectivo contado; se guarda la diferencia. No se puede cerrar con cuentas en `precuenta` sin avisarlo (advertencia con la lista, como al cerrar el local).
- Una caja abierta por local en esta versión (varias cajas simultáneas queda para después).
- Reporte de cierre imprimible: ventas por método, por tipo (salón, delivery, recojo), anulaciones, descuentos, diferencia.

---

## 6. Comprobantes manuales (sin SUNAT)

### 6.1 Advertencia que hay que resolver antes de construir

En Perú la boleta y la factura son comprobantes de pago electrónicos que se informan a SUNAT. Un documento impreso por FOORA con el título "BOLETA DE VENTA" que no se envía a SUNAT **puede no tener validez tributaria** y exponer al restaurante. No soy abogado ni contador; hay que confirmarlo con el contador del piloto. Mientras no exista la integración, hay dos formas seguras de trabajar, y el diseño soporta ambas:

- **Modo A, nota de venta interna.** FOORA emite un ticket con serie y correlativo propios, rotulado como documento interno sin valor tributario. El local emite el comprobante real en su sistema actual o en SUNAT.
- **Modo B, registro de comprobante externo.** El cajero emite la boleta o factura en su otro sistema y en FOORA solo anota tipo, serie y número. Sirve para cuadrar caja y reportes.
- **Modo C, emisión propia (lo que pediste).** FOORA numera y emite boleta y factura. Queda construido y probado, pero conviene activarlo por local solo cuando el envío a SUNAT esté listo, o bajo responsabilidad expresa del local.

Los tres modos usan la misma tabla y la misma pantalla, así que construir C no cuesta más: cambia el rótulo, la serie y un ajuste por restaurante (`comprobantes_modo`).

### 6.2 Reglas

| Regla | Detalle |
|---|---|
| Tipos | `boleta`, `factura`, `nota_venta` |
| Series | Por local y tipo, configurables. Convención electrónica: boleta `B001`, factura `F001`; nota de venta `NV01` |
| Correlativo | Sin huecos ni duplicados: `lockForUpdate` sobre la fila de la serie, mismo patrón que el número de pedido |
| Factura | Exige RUC (11 dígitos, validar dígito verificador), razón social y dirección fiscal |
| Boleta | Datos del cliente opcionales; **obligatorios (nombre y documento) desde S/ 700.00** |
| Documento del cliente | DNI (8 dígitos), carné de extranjería, pasaporte, RUC |
| Impuesto | Los precios de la carta **ya incluyen IGV**. Se desglosa hacia atrás: `base = total / (1 + tasa)`, `igv = total − base`, en céntimos enteros |
| Tasa | **Configurable por local** y copiada en cada comprobante. General 18%; restaurantes MYPE acogidos a la Ley 31556: 10.5% en 2026 y 15% en 2027 |
| Datos del emisor | RUC, razón social, nombre comercial, dirección fiscal: nueva pestaña "Facturación" en Configuración |
| Inmutabilidad | Un comprobante emitido no se edita. Se anula (marca + motivo) y se emite otro. El número anulado no se reutiliza |
| Copias | Las líneas del comprobante copian descripción, cantidad y precio; no dependen de la carta |
| Preparado para SUNAT | Columnas `estado_sunat` (`no_enviado` por defecto), `hash`, `xml_path`, `cdr_path`, `enviado_at`, todas nulas por ahora |

- **Clientes frecuentes:** guardar los datos de facturación (RUC y razón social) por local para autocompletar. Es dato personal: mismo cuidado que el teléfono del cliente.
- **Impresión:** vista de ticket de 80 mm con `window.print()`, más opción de enviar por WhatsApp con enlace público con código aleatorio (mismo patrón que el seguimiento, con `noindex`).
- Para la futura integración conviene evaluar un proveedor de facturación electrónica (PSE/OSE) en lugar de conectarse directo a SUNAT: reduce mucho el trabajo de firma y homologación.

---

## 7. Modelo de datos propuesto

Todas con `restaurante_id` y el trait `BelongsToRestaurante`. Migraciones nuevas, sin editar las anteriores.

**Salón**
- `areas`, `mesas`: usar las existentes. Añadir si faltan: `mesas.capacidad`, `mesas.orden`, `mesas.activo`, `mesas.cuenta_abierta_id` (nulo si está libre).
- `cuentas`: `numero` (correlativo por local), `estado` (`abierta`, `precuenta`, `cerrada`, `anulada`, `fusionada`), `mesero_id`, `comensales`, `fusionada_en_id`, `cierre_tipo` (`venta`, `externo`), `abierta_at`, `precuenta_at`, `cerrada_at`, `nota`.
- `cuenta_mesas`: `cuenta_id`, `mesa_id`, `desde_at`, `hasta_at`. (Revisar si `ocupaciones_mesa` ya cumple este papel; si es así, usarla y añadirle `cuenta_id`.)
- `pedidos`: añadir `cuenta_id` (nulo en delivery y recojo), `ronda`, `mesero_id`.
- `pedido_items`: añadir `anulado_at`, `anulado_por`, `motivo_anulacion`.

**Caja y ventas**
- `caja_sesiones`: `abierta_por`, `abierta_at`, `monto_inicial`, `cerrada_por`, `cerrada_at`, `efectivo_esperado`, `efectivo_contado`, `diferencia`, `observacion`.
- `caja_movimientos`: `caja_sesion_id`, `tipo` (`ingreso`, `egreso`), `monto`, `motivo`, `usuario_id`.
- `ventas`: `numero`, `caja_sesion_id`, `cuenta_id` (nulo), `pedido_id` (nulo), `subtotal`, `descuento`, `descuento_motivo`, `total`, `estado` (`pagada`, `anulada`), `cajero_id`, `anulado_at`, `anulado_por`, `motivo_anulacion`.
- `venta_pagos`: `venta_id`, `metodo_pago_id`, `monto`, `recibido`, `vuelto`, `referencia`.

**Comprobantes**
- `comprobante_series`: `tipo`, `serie`, `correlativo_actual`, `activo`. Único por (`restaurante_id`, `tipo`, `serie`).
- `comprobantes`: `venta_id`, `tipo`, `serie`, `correlativo`, `externo` (modo B), datos del cliente (`cliente_tipo_doc`, `cliente_num_doc`, `cliente_nombre`, `cliente_direccion`), `base_imponible`, `igv`, `tasa_igv`, `total`, `estado` (`emitido`, `anulado`), `emitido_por`, `emitido_at`, campos de anulación y campos SUNAT nulos. Único por (`restaurante_id`, `tipo`, `serie`, `correlativo`).
- `comprobante_items`: `descripcion`, `cantidad`, `precio_unitario`, `total`.
- `clientes_facturacion`: `tipo_doc`, `num_doc`, `nombre`, `direccion`.
- `restaurantes`: añadir `ruc`, `razon_social`, `direccion_fiscal`, `tasa_igv`, `comprobantes_modo`, `modulos` (JSON).

**Enums a ampliar:** `alertas.tipo` (+ `precuenta_pedida`, `pedido_listo_salon`), `actividad_log.modulo` (+ `salon`, `caja`, `comprobantes`), morph map (+ `cuenta`, `venta`, `comprobante`).

---

## 8. Reglas de negocio nuevas (referencia rápida)

| Regla | Valor |
|---|---|
| Mesas por cuenta | 1 a 10 |
| Comensales | 1 a 99 |
| Una mesa, una cuenta abierta | Garantizado con bloqueo de filas de `mesas` en orden de id |
| Pedido de salón | Entra en `en_preparacion`, sin pago propio |
| Cobrar | Exige caja abierta y suma de pagos igual al total |
| Boleta con datos obligatorios | Total desde S/ 700.00 |
| Factura | RUC válido de 11 dígitos + razón social |
| Correlativos | Sin huecos; los anulados no se reutilizan |
| Nada de dinero se borra | Ventas, pagos, comprobantes y líneas se anulan con marca y motivo |
| Precios | Solo los calcula el servidor (`CalculadorLineaPedido`) |

**Concurrencia que debe probarse en MySQL con procesos reales y control negativo** (regla de la sección 16 de v1):
1. Dos meseros abren la misma mesa a la vez: una cuenta se crea, el otro recibe 409.
2. Dos meseros unen mesas cruzadas (A une 4 y 5; B une 5 y 4): sin interbloqueo, gana uno.
3. Dos cajeros cobran la misma cuenta: una venta, el otro 409.
4. 200 comprobantes simultáneos de la misma serie: correlativos 1 a 200 sin huecos ni duplicados.
5. Enviar la misma ronda dos veces con la misma clave: un solo pedido.
6. Agregar una ronda mientras caja cobra: o entra antes del cobro y se incluye, o se rechaza; nunca queda un pedido sin cobrar en una cuenta cerrada.

**Seguridad:** todo lo nuevo va dentro del panel (sesión + CSRF + Policies). Datos de otro local responden 404. El enlace público del comprobante usa código aleatorio de 32 caracteres, `noindex` y no muestra más datos que los impresos.

---

## 9. Plan de construcción por fases

Un módulo por sesión de Claude Code, con plan aprobado antes de escribir código, como en v1.

| Fase | Contenido | Entregable que se puede mostrar al restaurante |
|---|---|---|
| **A. Mesas** | CRUD de áreas y mesas, límite `max_mesas`, módulos por restaurante | El admin dibuja su salón |
| **B. Salón** | Cuentas, unir mesas, tomar pedido, rondas, cocina, precuenta, cierre externo | El mesero trabaja con el celular. **Ya es vendible sin tocar su sistema de ventas** |
| **C. Caja** | Sesiones de caja, ventas, pago mixto, descuentos, anulaciones, cierre con arqueo | Cuadre de caja diario |
| **D. Comprobantes** | Series, boleta, factura, nota de venta, impresión, pestaña Facturación | Ticket impreso |
| **E. Reportes** | Ventas por día, método, mesero y producto; exportación | Lo que el dueño mira cada noche |
| **F. SUNAT** | Integración por API | Reemplazo completo del otro sistema |

Hacer un piloto real al terminar la fase B, antes de invertir en C y D.

**Prompt sugerido para la fase B:**

```
Contexto: lee CLAUDE.md, docs/FOORA_v1_documentacion.md, docs/FOORA_v1.1_salon_y_ventas.md y docs/INVENTARIO_TECNICO.md.

Cambio que quiero: construir el módulo Salón (sección 4 y la parte "Salón" de la sección 7 del documento v1.1).
1. Revisa las tablas areas, mesas y ocupaciones_mesa reales y dime si ocupaciones_mesa sirve como cuenta_mesas o si conviene la tabla nueva.
2. Cuentas con una o varias mesas (unir al abrir, agregar, quitar, mover, fusionar).
3. Mapa de mesas para el mesero, pensado primero para 390 px.
4. Tomar pedido reutilizando CalculadorLineaPedido; cada envío es un pedido tipo salon con cuenta_id y clave de idempotencia.
5. Cocina ve mesa, ronda y mesero. Estados: en_preparacion, listo, entregado ("Servido").
6. Precuenta y cierre "Cobrado en otro sistema". Todavía no hay ventas ni comprobantes.

Reglas de trabajo:
- Muéstrame primero un plan corto con las migraciones y espera mi aprobación.
- Respeta el aislamiento multi-tenant y las secciones 8 y 10 de v1.
- Pruebas de concurrencia en MySQL con control negativo para los casos 1, 2, 5 y 6 de la sección 8 de v1.1.
- Corre php artisan test dos veces y npm run build. Dime qué no pudiste verificar.
- No hagas commit.
```

---

## 10. Decisiones abiertas

1. **Modo de comprobantes** para el piloto: A, B o C (sección 6.1). Consultar al contador.
2. **¿Qué sistema de ventas usan** los locales del piloto y qué les falta? Define si la fase C vale la pena ahora.
3. **Planes:** ¿Salón entra en Pro y Caja en Premium, o se suben los precios?
4. **Mesero:** ¿ve todas las cuentas o solo las suyas por defecto?
5. **Ventas de hoy:** ¿desde `ventas` o desde pedidos entregados cuando el local no usa Caja?
6. **Cajero anula ventas** o solo el admin.
7. **Varias cocinas** (barra y cocina separadas): si algún local del piloto lo necesita, hay que añadir "estación" por categoría antes de la fase B.
