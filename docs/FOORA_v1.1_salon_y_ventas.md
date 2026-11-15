# FOORA v1.1: salón (mesero), caja y comprobantes manuales

> **Qué es este archivo.** Propuesta de diseño para ampliar FOORA v1 con tres módulos: **Salón** (mesas y mesero), **Caja y ventas**, y **Comprobantes manuales** (boleta, factura, nota de venta, sin conexión a SUNAT). Complementa `FOORA_v1_documentacion.md` y respeta sus reglas (secciones 6, 8 y 10).
>
> **Estado (15/11/2026).** Las fases **A (mesas), B1 (cuentas) y B2 (pedidos de salón)** están construidas. Las secciones 3, 4, 7 y 8 describen lo que hace el código; donde el diseño original y el código difieren, manda el código, y se indica. **Caja y ventas** (sección 5) y **Comprobantes** (sección 6) siguen **pendientes**: son la propuesta original, sin construir. El detalle técnico (migraciones, servicios, rutas, pruebas) está en `docs/INVENTARIO_TECNICO.md`.
>
> **Límites.** Los datos tributarios (sección 6) deben confirmarse con el contador de cada local.

---

## 1. El problema de fondo: "ya tienen otro sistema de ventas"

Si FOORA se presenta como reemplazo del sistema de ventas, compite contra algo que el restaurante ya paga, ya conoce y que ya emite comprobantes electrónicos. Es una venta difícil. La propuesta es que **FOORA nunca obligue a cambiar de sistema**: cada módulo se activa por separado y los tres niveles funcionan solos.

| Nivel | Qué usa el local | Qué pasa con su sistema actual |
|---|---|---|
| **1. Canal online** (v1 actual) | Vitrina, pedidos delivery y recojo | Lo sigue usando igual. FOORA solo le trae pedidos |
| **2. Salón** | Mesero toma pedidos en el celular, cocina los ve, precuenta | Lo sigue usando para cobrar y emitir. El cajero lee el total en la precuenta de FOORA y lo registra allá |
| **3. Caja y comprobantes** | Cobro, caja diaria, boleta y factura desde FOORA | Lo reemplaza (o lo deja solo para el envío a SUNAT hasta que FOORA lo integre) |

Consecuencias de diseño:

1. **Módulos por restaurante, no globales.** `config/foora.php` enciende un módulo para toda la plataforma, y además cada local tiene su interruptor: la columna JSON `restaurantes.modulos`, que controla el superadmin y que podrá atarse al plan.
   - **Construido:** la clave real es **`mesas`**, y cubre todo el nivel 2 (áreas, mesas, cuentas, rondas y precuenta). No existe una clave `salon`.
   - **Propuesta pendiente:** `caja` y `comprobantes`, para el nivel 3. Se crearán con esos módulos.
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

Todo el salón vive en el módulo **`mesas`**, que se enciende local por local (sección 7). Con el módulo apagado, sus rutas responden 404 para todos. El repartidor no entra (403), igual que la cocina, salvo lo que se indica.

| Acción | admin | cajero | mesero | cocina | Estado |
|---|---|---|---|---|---|
| Encender o apagar el módulo en el local | Solo el superadmin, desde el detalle del restaurante | | | | Construido |
| Crear y editar áreas y mesas ("Editar salón") | Sí | No | No | No | Construido |
| Ver el plano de mesas | Sí | Sí | Sí | No | Construido |
| Abrir cuenta, unir mesas, tomar pedido y enviar rondas | Sí | Sí | Sí | No | Construido |
| Agregar, quitar o mover mesas; unir cuentas; transferir | Sí | Sí | Sí, **cualquier cuenta del local** | No | Construido |
| Anular una línea ya enviada (con motivo) | Sí | Sí | No | No | Construido |
| Cancelar una ronda entera sin servir (con motivo) | Sí | Sí | No | No | Construido |
| Pedir precuenta e imprimirla | Sí | Sí | Sí | No | Construido |
| Marcar una ronda como lista | Sí | Sí | No | Sí | Construido |
| Marcar una ronda como servida | Sí | Sí | Sí | No | Construido |
| Cerrar la cuenta ("Cobrado en otro sistema") | Sí | Sí | No | No | Construido |
| Anular la cuenta (con motivo) | Sí | Sí | Solo una cuenta suya **sin pedidos vivos** | No | Construido |
| Abrir y cerrar caja, cobrar | Sí | Sí | No | No | **Pendiente** (Caja) |
| Emitir comprobante | Sí | Sí | No | No | **Pendiente** (Comprobantes) |
| Anular venta o comprobante | Sí | Configurable | No | No | **Pendiente** |
| Configurar series e impuestos | Sí | No | No | No | **Pendiente** |

- **Inicio del mesero:** con el módulo encendido va directo al plano de mesas; sin él sigue viendo "Tu cuenta aún no tiene módulos habilitados".
- **El mesero ve todas las cuentas del local.** El ajuste "solo las suyas", propuesto en el diseño, no se construyó. Lo único limitado a sus propias cuentas es anularlas (y solo si no tienen pedidos vivos).
- **El mesero no "solicita" anulaciones:** pide al admin o al cajero que anulen la línea. No hay flujo de solicitud.
- **Cocina no recibe montos de ningún pedido** (salón, delivery ni recojo): ni en la lista, ni en el detalle, ni en la actualización en vivo. Tampoco datos del cliente.
- Datos de otro local responden **404**: mesas, áreas, cuentas, rondas y líneas.

---

## 4. Módulo Salón (mesero)

### 4.1 Concepto central: la **cuenta**

Una **cuenta** es una atención abierta en una o varias mesas. Resuelve "dos mesas juntas": la cuenta tiene muchas mesas, y los pedidos cuelgan de la cuenta, no de la mesa.

- **Las mesas de una cuenta** son sus filas en `ocupaciones_mesa` con `salida_at` nulo (no se creó `cuenta_mesas`). Una mesa solo puede tener **una ocupación abierta**: lo garantiza la base con un índice único.
- **Invariante:** una mesa está `ocupada` si y solo si tiene una ocupación abierta. Entrar o salir de una cuenta cambia `mesas.estado` en la misma transacción.
- **Cada envío a cocina (ronda)** es un `pedido` normal con `tipo = salon`, `origen = local`, `cuenta_id` y `ronda`. Reutiliza `CalculadorLineaPedido`, las copias de nombres y precios, el historial y la pantalla de cocina.
- **El total de la cuenta** es la suma de sus pedidos no cancelados (sus totales ya excluyen las líneas anuladas), **calculada siempre en el servidor**.
- **Número de cuenta:** correlativo por local, sin huecos; una cuenta anulada conserva su número.
- **Control de cambios:** cada cuenta tiene una `version` que sube con cada operación. Quien actúa envía la que veía; si cambió, recibe **409** y la pantalla se actualiza. Las rondas **no** suben la `version`.
- **Nada se borra:** una cuenta se cierra, se anula (con motivo) o queda fusionada en otra; sus ocupaciones quedan como historial.

### 4.2 Flujo del mesero (pensado para celular, 390 px)

1. **Plano de mesas** (`panel/mesas`), con pestañas por área. Cada mesa muestra código, capacidad y estado por color y texto: *Disponible* (verde), *Ocupada* (naranja) y *Fuera de servicio* (rojo). *Reservada* (morado) ya está en el componente, pero aún no se usa.
   - **Una mesa ocupada muestra:** tiempo transcurrido, comensales, mesero, la etiqueta del grupo ("4 + 5"), el monto acumulado, "Precuenta pedida" y la marca **"Listo para servir"** si alguna ronda está lista.
   - **Las cuentas con más de 6 horas** se resaltan con borde rojo. No se cierran solas.
   - **El plano se actualiza cada 10 s**, con pausa si la pestaña está oculta. Cada consulta hace siempre el mismo número de consultas a la base, sin importar cuántas mesas o rondas haya.
2. **Abrir cuenta:** se toca una mesa disponible y se indican los comensales (1 a 99) y una nota opcional. Con **"Unir con otras mesas"** se eligen varias entre las disponibles de cualquier área, hasta 10 por cuenta. Si una mesa dejó de estar libre, responde 409.
3. **Tomar pedido** (`panel/mesas/cuentas/{cuenta}/pedido`): la misma carta de la vitrina, con búsqueda, chips de categoría, variantes, opciones con sus reglas, cantidad y observación por línea. Los productos agotados no se pueden pedir.
   - **El borrador** vive en el navegador, por cuenta, con ids, cantidades, opciones y la nota de cada línea. Nunca guarda precios: el total del borrador lo calcula el servidor cada vez que cambia.
4. **Enviar a cocina:** crea la ronda con una **clave de idempotencia**. Si el wifi falla y el mesero vuelve a tocar, recibe la misma ronda, no otra.
   - Si el servidor rechaza una línea (agotada, cambiada o de otro local), la respuesta indica cuál, el borrador la marca y el resto no se pierde.
5. **Ver cuenta** (hoja inferior desde el plano, o página propia):
   - Rondas con su estado (en preparación, listo para servir, servido, cancelada) y sus líneas; las anuladas, tachadas con su motivo.
   - El total del servidor y el botón **"Agregar más"**.
   - Según el rol: "Servido", anular línea y cancelar ronda.
6. **Pedir precuenta:** la cuenta pasa a `precuenta` (sube `version`, guarda `precuenta_at`) y se avisa a caja con la alerta `precuenta_pedida`, que se ve en el inicio. Se imprime en una vista de 80 mm rotulada **"Precuenta. No es comprobante de pago"**.
   - Pedirla otra vez sin cambios no duplica la alerta.
   - Exige que la cuenta tenga algo que cobrar.
7. **Cierre "Cobrado en otro sistema"** (admin o cajero): el cierre por venta llega con la Caja (pendiente).
   - **Total:** se envía el que se estaba viendo; si cambió en el servidor, 409.
   - **Rondas sin servir:** si quedan, responde 409 con la lista hasta que se confirme. Al confirmar, se marcan servidas.
   - **Al cerrar:** todos los pedidos no cancelados quedan `pagado` y todas las mesas, disponibles.

### 4.3 Operaciones sobre mesas

Todas exigen la `version` que se veía (409 si cambió) y solo valen sobre cuentas abiertas o en precuenta.

| Operación | Regla construida |
|---|---|
| **Unir al abrir** | Todas las mesas elegidas deben estar disponibles, activas y en un área activa; de 1 a 10 |
| **Agregar mesa a una cuenta abierta** | La mesa nueva debe estar disponible; la cuenta no pasa de 10 mesas |
| **Quitar una mesa** | La cuenta debe conservar al menos una mesa |
| **Mover de mesa** | Cambia una mesa por otra disponible; cierra la ocupación vieja y abre otra; los pedidos no se tocan |
| **Fusionar dos cuentas abiertas** | Se compara la `version` de las dos. Las mesas y los **pedidos** de la segunda pasan a la primera; la segunda queda `fusionada` (no se borra). Se suman los comensales (tope 99) y queda el mesero de la primera. Juntas no pasan de 10 mesas. La regla "no si alguna ya tiene venta" queda **pendiente** hasta que exista la Caja |
| **Renumerar rondas al fusionar** | Las rondas de la segunda van al final, en su orden (1–2 y 1–3 quedan 1–5); las de la primera no cambian. Cada pedido movido lo deja en su historial |
| **Transferir a otro mesero** | A una persona activa del local con rol admin, cajero o mesero. Cambia `mesero_id` y queda en la auditoría |
| **Anular la cuenta** | Con motivo. Cancela todos sus pedidos no cancelados, **también los servidos**, y deja una entrada en `pedido_historial` por pedido con usuario y motivo |

**Reglas cruzadas con la gestión de mesas:**
- Una mesa ocupada no se pone fuera de servicio ni se desactiva.
- Un área con mesas ocupadas no se desactiva.
- A mano solo se pasa entre disponible y fuera de servicio: `ocupada` solo la ponen las cuentas.

### 4.4 Estados de los pedidos de salón

- Entran directo a **`en_preparacion`**, con `aceptado_at`. No pasan por `nuevo`: no hay nada que aceptar ni pago que verificar. No exigen local abierto ni pedido mínimo, y no crean la alerta `pedido_nuevo`.
- **En el panel del cajero no suenan como "pedido nuevo"** ni suman al badge. En **cocina sí avisan con sonido**, con la etiqueta "Mesa 4 + 5 · Ronda 2 · Luis".
- `en_preparacion → listo` (cocina, admin o cajero) `→ entregado` con la etiqueta **"Servido"** (mesero, admin o cajero). `en_camino` no aplica.
- **Aviso al mesero:** cuando una ronda pasa a `listo`, su mesa muestra "Listo para servir" en la siguiente actualización del plano (cada 10 s). No hay sonido para el mesero.
- **El pago no se maneja por pedido:** las rondas quedan con pago `pendiente` y pasan a `pagado` al cerrarse la cuenta. Servir no cobra. La regla "no se entrega con pago por verificar" no les aplica.
- **Anular una línea ya enviada** (admin o cajero, con motivo):
  - Se marca (`anulado_at`, `anulado_por`, `motivo_anulacion`), no se borra.
  - Se recalculan el subtotal y el total del pedido.
  - Si se anulan todas las líneas, el pedido pasa a `cancelado`.
  - Antes de enviar, el mesero edita su borrador libremente.
- **Cancelar una ronda entera** (admin o cajero, con motivo): solo si aún no se sirvió. Una ronda servida se corrige anulando sus líneas.
- **Una ronda nueva sobre una cuenta en precuenta** la devuelve a `abierta` y resuelve la alerta. La siguiente precuenta crea una alerta nueva.

### 4.5 Impacto en lo existente

- **Panel de pedidos:**
  - Filtro de tipo "Salón", solo si el local tiene el módulo.
  - Los pedidos de salón no muestran botones de contacto ni de pago; dicen "Por cuenta".
  - El badge de "nuevos" no cambia.
- **Cocina:** cada pedido de salón muestra "Mesa 4 + 5 · Ronda 2 · Luis", con las mesas actuales de la cuenta.
- **Inicio** (admin y cajero):
  - **"Mesas ocupadas x/total"**, con el módulo activo.
  - **"Pedidos de hoy"** cuenta las rondas.
  - **"Ventas de hoy"** suma delivery y recojo entregados hoy, más las rondas no canceladas de **cuentas cerradas hoy**: no cuentan al servirse, y las de cuentas anuladas tampoco.
  - Cuando exista la Caja, esta cifra debería salir de `ventas` (decisión **pendiente**).
- **Límite del plan:** `planes.max_mesas` se aplica al crear, al agregar varias y al reactivar mesas.
- **Seguimiento público:** un pedido de salón nunca se ve por su código (404).
- **Usuarios:** desactivar a alguien con cuentas abiertas a su nombre no se bloquea; se avisa con el conteo para transferirlas.
- **Local no operativo o suspendido:**
  - **Sí:** ver todo, servir rondas, cerrar y anular las cuentas abiertas.
  - **No:** abrir cuentas, modificarlas, enviar rondas, anular líneas, cancelar rondas ni pedir precuenta.

---

## 5. Módulo Caja y ventas

> **Estado: pendiente.** Nada de esta sección está construido. Mientras tanto, las cuentas de salón se cierran con "Cobrado en otro sistema".

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

> **Estado: pendiente.** Nada de esta sección está construido. La única impresión que existe es la precuenta, rotulada "Precuenta. No es comprobante de pago".

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

## 7. Modelo de datos

Todas las tablas tienen `restaurante_id` y sus modelos el trait `BelongsToRestaurante`. Las migraciones son nuevas, sin editar las anteriores. Cada una sube, baja y vuelve a subir en MySQL. Columnas, índices y archivos de migración: `docs/INVENTARIO_TECNICO.md`.

**Módulos por local (construido)**
- `restaurantes.modulos` (JSON, nulo = ninguno): lista de módulos encendidos por el superadmin, por ejemplo `["mesas"]`. **Una sola clave, `mesas`, cubre áreas, mesas, cuentas y pedidos de salón.** Las claves `salon`, `caja` y `comprobantes` del diseño no se crearon; Caja y Comprobantes tendrán la suya cuando se construyan.

**Salón (construido)**
- **`areas`**: ya tenía todo lo necesario (`nombre`, `activa`, `orden`, único por local).
- **`mesas`**:
  - `capacidad` y `activa` ya existían. Se agregó `orden`, y `codigo` pasó a 20 caracteres.
  - El código es único **solo entre las mesas activas**, con la columna generada `codigo_activo` y el índice `uq_mesa_activa`.
  - `mesas.cuenta_abierta_id` **no se creó**: la mesa ocupada se deduce de su ocupación abierta.
- **`cuentas`**:
  - Columnas: `numero` (único por local), `estado` (`abierta`, `precuenta`, `cerrada`, `anulada`, `fusionada`), **`version`**, `mesero_id`, `comensales`, `abierta_at`, `precuenta_at`, `cerrada_at`, `cerrada_por`, `cierre_tipo` (`externo`, `venta`), `fusionada_en_id`, `nota`, y la anulación: `anulada_at`, `anulada_por`, `motivo_anulacion`.
  - `cierre_tipo = venta` existe pero nada llega a él todavía.
- **`cuenta_mesas` no se creó:** se usa **`ocupaciones_mesa`**.
  - Se le agregó `cuenta_id`, y `personas` y `consumo_total` pasaron a nullable.
  - La columna generada `mesa_abierta_id`, con índice único, garantiza una sola ocupación abierta por mesa.
  - `personas`, `mesero_id`, `pedido_id` y `consumo_total` no se usan con cuentas: esos datos viven en la cuenta.
- **`pedidos`**:
  - Se agregaron `cuenta_id` (nulo en delivery y recojo) y `ronda`, únicos juntos por cuenta (`uq_ped_ronda`).
  - `nombre_cliente`, `telefono_cliente` y `metodo_pago_id` pasaron a nullable; la vitrina los sigue exigiendo.
  - **`mesero_id` no se creó:** quién envió la ronda va en `creado_por`, que ya existía.
  - `mesa_id` no se usa en salón: una ronda puede ser de varias mesas, que salen de la cuenta.
- **`pedido_items`**: se agregaron `anulado_at`, `anulado_por` y `motivo_anulacion`.

**Caja y ventas (pendiente)**
- `caja_sesiones`: `abierta_por`, `abierta_at`, `monto_inicial`, `cerrada_por`, `cerrada_at`, `efectivo_esperado`, `efectivo_contado`, `diferencia`, `observacion`.
- `caja_movimientos`: `caja_sesion_id`, `tipo` (`ingreso`, `egreso`), `monto`, `motivo`, `usuario_id`.
- `ventas`: `numero`, `caja_sesion_id`, `cuenta_id` (nulo), `pedido_id` (nulo), `subtotal`, `descuento`, `descuento_motivo`, `total`, `estado` (`pagada`, `anulada`), `cajero_id`, `anulado_at`, `anulado_por`, `motivo_anulacion`.
- `venta_pagos`: `venta_id`, `metodo_pago_id`, `monto`, `recibido`, `vuelto`, `referencia`.

**Comprobantes (pendiente)**
- `comprobante_series`: `tipo`, `serie`, `correlativo_actual`, `activo`. Único por (`restaurante_id`, `tipo`, `serie`).
- `comprobantes`: `venta_id`, `tipo`, `serie`, `correlativo`, `externo` (modo B), datos del cliente (`cliente_tipo_doc`, `cliente_num_doc`, `cliente_nombre`, `cliente_direccion`), `base_imponible`, `igv`, `tasa_igv`, `total`, `estado` (`emitido`, `anulado`), `emitido_por`, `emitido_at`, campos de anulación y campos SUNAT nulos. Único por (`restaurante_id`, `tipo`, `serie`, `correlativo`).
- `comprobante_items`: `descripcion`, `cantidad`, `precio_unitario`, `total`.
- `clientes_facturacion`: `tipo_doc`, `num_doc`, `nombre`, `direccion`.
- `restaurantes`: añadir `ruc`, `razon_social`, `direccion_fiscal`, `tasa_igv`, `comprobantes_modo`.

**Enums y morph map**
- **Construido:**
  - `alertas.tipo` + `precuenta_pedida`.
  - Morph map + `area` y `cuenta` (`mesa` ya estaba).
  - `actividad_log.modulo` no cambió: todo el salón se audita con el módulo `mesas`, que ya existía, y el interruptor del superadmin con `suscripciones`.
- **No se crearon:** `pedido_listo_salon` (el aviso de "listo" es la marca en el plano) ni los módulos de auditoría `salon`, `caja` y `comprobantes`.
- **Pendiente:** morph map + `venta` y `comprobante`.

---

## 8. Reglas de negocio nuevas (referencia rápida)

| Regla | Valor | Estado |
|---|---|---|
| Módulo por local | `restaurantes.modulos` con `mesas`; apagado = 404 | Construido |
| Mesas activas por plan | No pasan `planes.max_mesas` (nulo = sin tope); cuentan aunque su área esté desactivada | Construido |
| Código de mesa | Único entre las activas; capacidad 1 a 30 | Construido |
| Mesas por cuenta | 1 a 10 (al abrir, agregar y fusionar) | Construido |
| Comensales | 1 a 99 | Construido |
| Una mesa, una cuenta abierta | **Bloqueo de la fila del restaurante** en cada operación, más el índice único `uq_ocu_mesa_abierta` como última defensa. No se bloquean filas de `mesas` (cambio respecto del diseño) | Construido |
| Cambios concurrentes sobre una cuenta | `cuentas.version`; si cambió, 409 | Construido |
| Número de cuenta | Correlativo por local, sin huecos ni duplicados | Construido |
| Pedido de salón | Entra en `en_preparacion`, sin pago propio; mismo correlativo que la vitrina | Construido |
| Límites de una ronda | 30 líneas, cantidad 1 a 50, observación de 200 caracteres (iguales a los del carrito público) | Construido |
| Reenvío de una ronda | La misma clave devuelve la misma ronda | Construido |
| Cerrar sin Caja | Exige el total que se veía y confirmar las rondas sin servir; todo lo no cancelado queda pagado | Construido |
| Anular cuenta con pedidos | Solo admin o cajero; cancela todos, también los servidos | Construido |
| Cobrar | Exige caja abierta y suma de pagos igual al total | **Pendiente** |
| Boleta con datos obligatorios | Total desde S/ 700.00 | **Pendiente** |
| Factura | RUC válido de 11 dígitos + razón social | **Pendiente** |
| Correlativos de comprobantes | Sin huecos; los anulados no se reutilizan | **Pendiente** |
| Nada de dinero se borra | Líneas, rondas y cuentas se anulan con marca y motivo; también ventas, pagos y comprobantes cuando existan | Construido / Pendiente |
| Precios | Solo los calcula el servidor (`CalculadorLineaPedido`) | Construido |

**Concurrencia probada en MySQL con procesos reales y control negativo** (scripts en `tests/Concurrencia/`, que reconstruyen la base `foora_concurrencia` antes de cada corrida):

| # | Caso | Estado | Script |
|---|---|---|---|
| 1 | Varios meseros abren la misma mesa a la vez: una cuenta, los demás 409 | Probado (8 y 12 procesos) | `cuentas.php` (`misma_mesa`) |
| 2 | Dos meseros unen mesas cruzadas (4+5 y 5+4): sin interbloqueo, gana uno | Probado | `cuentas.php` (`cruzadas`) |
| 3 | Dos cajeros cobran la misma cuenta: una venta, el otro 409 | **Pendiente** (Caja) | |
| 4 | 200 comprobantes simultáneos de la misma serie: correlativos 1 a 200 | **Pendiente** (Comprobantes) | |
| 5 | La misma ronda enviada varias veces con la misma clave: un solo pedido, y todos reciben ese | Probado | `pedidos_salon.php` (`misma_clave`) |
| 6 | Enviar una ronda mientras se cierra la cuenta: nunca queda un pedido vivo en una cuenta cerrada | Probado con el cierre "Cobrado en otro sistema"; con la Caja, **pendiente** | `pedidos_salon.php` (`ronda_y_cierre`) |
| 7 | Numeración de cuentas sin huecos ni duplicados | Probado | `cuentas.php` (`numeracion`) |
| 8 | Pedidos de la vitrina y rondas de salón a la vez: números sin huecos ni duplicados | Probado | `pedidos_salon.php` (`numeracion`) |
| 9 | Fusionar y agregar mesa a la vez sobre la misma cuenta: gana uno, el otro 409 | Probado | `pedidos_salon.php` (`fusion_y_mesa`) |
| 10 | Varios admins crean, agregan o reactivan mesas al límite del plan: nunca se pasa | Probado | `limite_mesas.php` |

Lo que mostraron los controles negativos:
- **Sin el bloqueo del restaurante:** los índices únicos todavía evitan el dato duplicado, pero quienes pierden chocan con el índice en vez de recibir la regla, y se pierden operaciones legítimas.
- **Sin índices:** aparecen interbloqueos de InnoDB o el dato se corrompe (varias cuentas en una mesa, números repetidos).

**Seguridad:**
- Todo lo nuevo va dentro del panel: sesión, CSRF y policies (`MesaPolicy`, `AreaPolicy`, `CuentaPolicy`, `PedidoPolicy`).
- Datos de otro local responden 404.
- Las observaciones del mesero se guardan como texto y siempre se pintan escapadas.
- **Pendiente:** el enlace público del comprobante (código aleatorio de 32 caracteres, `noindex`).

---

## 9. Plan de construcción por fases

Un módulo por sesión de Claude Code, con plan aprobado antes de escribir código, como en v1.

| Fase | Contenido | Entregable que se puede mostrar al restaurante | Estado |
|---|---|---|---|
| **A. Mesas** | CRUD de áreas y mesas, límite `max_mesas`, módulos por restaurante | El admin dibuja su salón | **Hecha** (11/11/2026) |
| **B. Salón** | **B1:** cuentas, unir mesas, mover, fusionar, transferir, cierre externo y anulación. **B2:** tomar pedido, rondas, cocina, servir, anular líneas, precuenta | El mesero trabaja con el celular. **Ya es vendible sin tocar su sistema de ventas** | **Hecha**: B1 y B2 (15/11/2026) |
| **C. Caja** | Sesiones de caja, ventas, pago mixto, descuentos, anulaciones, cierre con arqueo | Cuadre de caja diario | Pendiente |
| **D. Comprobantes** | Series, boleta, factura, nota de venta, impresión, pestaña Facturación | Ticket impreso | Pendiente |
| **E. Reportes** | Ventas por día, método, mesero y producto; exportación | Lo que el dueño mira cada noche | Pendiente |
| **F. SUNAT** | Integración por API | Reemplazo completo del otro sistema | Pendiente |

Hacer un piloto real al terminar la fase B, antes de invertir en C y D. Con A y B hechas, el siguiente paso es ese piloto.

**Prompt sugerido para la fase B** (ya usado para B1 y B2; queda como referencia del formato):

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
4. **Mesero.** **Resuelta:** ve y opera todas las cuentas del local. Solo anular está limitado a sus propias cuentas, y únicamente si no tienen pedidos vivos. El ajuste "solo las suyas" no se construyó.
5. **Ventas de hoy.** **Resuelta:** sin Caja, una ronda de salón cuenta cuando su cuenta se **cierra** hoy, no al servirse; las de cuentas anuladas no cuentan. Delivery y recojo siguen contando al entregarse. Cuando exista la Caja habrá que decidir si la cifra pasa a salir de `ventas`.
6. **Cajero anula ventas** o solo el admin.
7. **Varias cocinas** (barra y cocina separadas). Hoy todas las rondas llegan a una sola pantalla de cocina. Si un local del piloto tiene barra y cocina separadas, "estación por categoría" se agrega como mejora posterior al salón, antes de la Caja. Sigue abierta.
