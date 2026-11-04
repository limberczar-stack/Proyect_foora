# FOORA: alcance de la versión base

Lema: "Reserva, ordena y disfruta". Equipo: Group RB. Ciudad inicial: Huánuco (Perú).

## Qué es
Plataforma SaaS que conecta clientes y restaurantes con reservas inteligentes, pedidos autogestionados y administración propia de cada local. No tiene repartidores propios: cada restaurante gestiona su operación. Sin comisión por venta.

## Problema que resuelve
- Restaurantes: saturación en feriados, reservas desordenadas, pedidos solo por WhatsApp, poca digitalización, comisiones altas de otras plataformas.
- Clientes: esperas largas, poca información en tiempo real, mala experiencia al reservar.

## Modelo de negocio (suscripción mensual por local)
- Plan Básico: S/ 39
- Plan Pro: S/ 79
- Plan Premium: S/ 129
- Tiempo gratuito de entrada para ganar clientes fieles.

## Mercado
- Cliente final: 18 a 45 años.
- Restaurantes: pollerías, cevicherías, cafeterías, fast food, chifas.
- Fases: 1) Huánuco (1 a 20 vitrinas virtuales), 2) validación con aliados (20 a 60), 3) expansión nacional, 4) por decidir.

## Diferencial frente a PedidosYa, Rappi, DiDi Food y WhatsApp
Reservas inteligentes, control de aforo, organización en fechas festivas, gestión autónoma del restaurante y comisión baja.

## Panel del restaurante (módulos)
Menú lateral con badges: Pedidos, Alertas, Reservas, Mesas, Actividad, Promociones, Configuración.

1. **Inicio:** tarjetas de resumen (reservas hoy, pedidos hoy, mesas ocupadas x/total, alertas pendientes, actividad), alertas pendientes, pedidos recientes, reservas próximas, mesas en tiempo real, actividad reciente, promociones activas.
2. **Pedidos:** pestañas Nuevos, En preparación, Listos, En camino, Entregados, Cancelados. Filtros Todos, Delivery, Recojo en tienda. Tabla a la izquierda y detalle del pedido a la derecha (cliente, tipo, método de pago, productos, observaciones, aceptar o rechazar). Botón imprimir tickets.
3. **Alertas:** urgentes, medias e informativas (pago por validar, pedido sin respuesta, reserva por vencer, solicitud de aplazamiento, ocupación alta, menú incompleto), con panel de detalle y acciones.
4. **Reservas:** pestañas Todas, Hoy, Mañana, Próximos días, Canceladas, No asistieron. Tarjetas de resumen, tabla, próximas reservas, detalle con editar, enviar recordatorio y cancelar. Código tipo RES-240525-0012.
5. **Mesas:** plano por áreas (Salón principal, Terraza, configurables), estados Disponible, Ocupada, Reservada, Fuera de servicio, detalle con mesero, tiempo ocupada, consumo y acciones rápidas.
6. **Actividad:** historial de acciones con usuario, módulo, IP y dispositivo; filtros; revertir acción (solo administradores).
7. **Promociones:** crear y medir promociones (2x1, porcentaje, delivery gratis, combo), vigencia, usos y límite, ventas generadas.
8. **Configuración:** General, Horario, Pago, Notificaciones, Impresión, Integraciones, Usuarios, Seguridad.

## App del cliente (después del panel)
Inicio con promociones y restaurantes más solicitados, Delivery, Recojo en local, Reservas, Favoritos, registro e inicio de sesión. Barra inferior: Inicio, Explorar, Pedidos, Notificaciones, Perfil.

## Métodos de pago
Efectivo, Yape, Plin y tarjeta (POS). Yape y Plin se validan manualmente por el local.

## Guía visual (tomada de los mockups)
- Estilo: limpio y moderno, fondo claro, tarjetas blancas con bordes suaves y sombras discretas, esquinas redondeadas.
- Color de marca: morado intenso, punto de partida `#5B21B6` (ajustar con el cuentagotas sobre los PNG de `docs/mockups/`). El logo "FOORA" va en morado.
- Colores de estado:
  - Verde: confirmada, entregado, listo, disponible, pagado.
  - Naranja: pendiente, en preparación, ocupada, por verificar.
  - Azul: nuevo, en camino, informativa.
  - Rojo: cancelado, urgente, no asistió, fuera de servicio.
  - Morado: reservada, seleccionado, acciones principales.
- Elementos recurrentes: tarjetas de estadística con ícono en recuadro de color, badges de estado, pestañas con contador, tabla con panel de detalle lateral, botones de acción verde (aceptar) y rojo suave (rechazar).
- Uso: el personal del local usa celular, tablet y PC; el diseño debe ser móvil primero.
- Textos de la interfaz en español (Perú), montos en soles (S/).

## Stack
Laravel 13, PHP 8.3, Blade, Tailwind 4, jQuery, MySQL. Multi-tenant por `restaurante_id`.
