/**
 * FOORA: vitrina pública (jQuery). Entrada propia: no carga el JS del panel en el celular.
 *
 * - Carta: búsqueda y chips de categorías (carta/busqueda.js).
 * - Hojas: bottom sheet en celular y modal en escritorio, con arrastre para cerrar (carta/hojas.js).
 * - Detalle de producto: variante, grupos de opciones, cantidad, observaciones y total en vivo (carta/detalle.js).
 * - Carrito: se guarda en localStorage solo con ids, cantidades y observaciones. Los precios del
 *   carrito siempre vienen del servidor.
 * - Finalizar pedido: datos, método de pago y envío. El servidor recalcula todo.
 *
 * Lo de resources/js/carta/ lo comparte con la toma de pedidos del salón.
 *
 * Los nombres y descripciones los escribe cada restaurante: todo dato se pinta con .text() o
 * .val(), nunca con .html() ni concatenado en una plantilla.
 */
import $ from 'jquery';
import { initBusqueda, initChips } from './carta/busqueda';
import { initDetalle } from './carta/detalle';
import { abrirHoja, initHojas } from './carta/hojas';
import { aCentimos, almacen, claveLinea, el, nuevaClaveIdempotencia, sanearLineas, soles, solesDeCentimos, textoLineaLocal } from './carta/util';

const datos = window.FOORA_VITRINA;

const ESPERA_VALIDAR_MS = 400;
const TIEMPO_MAXIMO_VALIDAR_MS = 15000;
const CLAVE_CARRITO = `foora.carrito.v1.${datos.slug}`;
const ESPERA_CONFIRMAR_VACIAR_MS = 3000;
const TIEMPO_MAXIMO_PEDIDO_MS = 20000;
const CLAVE_MI_PEDIDO = `foora.pedido.v1.${datos.slug}`;

function anunciar(mensaje) {
    $('[data-anuncio]').text(mensaje);
}

/* -------------------------------------------------------------------------- */
/* Carrito: estado y almacenamiento                                            */
/* -------------------------------------------------------------------------- */

function leerCarrito() {
    try {
        return sanearLineas(JSON.parse(almacen.leer(CLAVE_CARRITO)), { lineasMaximas: datos.lineasMaximas, cantidadMaxima: datos.cantidadMaxima });
    } catch {
        return [];
    }
}

const carrito = {
    lineas: leerCarrito(),
    respuesta: null,
    estado: 'inactivo',
    error: '',
    espera: null,
    peticion: null,
};

function guardarCarrito() {
    almacen.escribir(CLAVE_CARRITO, JSON.stringify({ lineas: carrito.lineas }));
}

/** Tras cualquier cambio: guarda, invalida los precios y cancela la consulta en curso. */
function cambioEnCarrito() {
    guardarCarrito();
    reprogramarValidacion();
}

function reprogramarValidacion() {
    carrito.peticion?.abort();
    carrito.peticion = null;
    carrito.respuesta = null;
    clearTimeout(carrito.espera);
    carrito.estado = carrito.lineas.length ? 'cargando' : 'inactivo';

    if (carrito.lineas.length) {
        carrito.espera = setTimeout(validarCarrito, ESPERA_VALIDAR_MS);
    }

    pintarCarrito();
}

function motivoError(xhr) {
    if (xhr.status === 429) {
        return 'Hiciste muchas consultas seguidas. Espera un momento e inténtalo otra vez.';
    }

    if (xhr.status === 404) {
        return 'Este local ya no está disponible.';
    }

    if (xhr.status === 409) {
        return xhr.responseJSON?.message || 'Este restaurante no está disponible por ahora.';
    }

    if (xhr.status === 422) {
        return xhr.responseJSON?.message || 'No pudimos revisar tu carrito.';
    }

    return 'No pudimos confirmar los precios. Revisa tu conexión e inténtalo otra vez.';
}

function validarCarrito() {
    carrito.peticion?.abort();
    carrito.estado = 'cargando';
    pintarCarrito();

    // Solo viajan ids y cantidades, nunca precios.
    const lineas = carrito.lineas.map(({ producto_id, variante_id, opciones, cantidad }) => ({ producto_id, variante_id, opciones, cantidad }));

    const peticion = $.ajax({
        url: datos.urlValidar,
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ lineas }),
        dataType: 'json',
        headers: { Accept: 'application/json' },
        timeout: TIEMPO_MAXIMO_VALIDAR_MS,
    });

    carrito.peticion = peticion;

    peticion
        .done((respuesta) => {
            carrito.respuesta = respuesta;
            carrito.estado = 'listo';
            carrito.error = '';
            anunciar(`Subtotal del carrito: ${soles(respuesta.subtotal)}`);
        })
        .fail((xhr, estado) => {
            if (estado === 'abort') {
                return;
            }

            carrito.estado = 'error';
            carrito.error = motivoError(xhr);
            anunciar(carrito.error);
        })
        .always(() => {
            if (carrito.peticion === peticion) {
                carrito.peticion = null;
                pintarCarrito();
            }
        });
}

/* -------------------------------------------------------------------------- */
/* Carrito: pintado                                                            */
/* -------------------------------------------------------------------------- */

function pintarLinea(linea, indice) {
    const servidor = carrito.respuesta?.lineas[indice];
    const local = textoLineaLocal(datos.productos, linea);
    const $fila = el('li', 'py-3').attr('data-linea', indice);

    const $cabecera = el('div', 'flex items-start justify-between gap-3');
    $cabecera.append(el('p', 'min-w-0 text-[0.9375rem] font-semibold leading-snug', servidor?.nombre ?? local.nombre));

    if (carrito.estado === 'cargando') {
        $cabecera.append(el('span', 'skeleton mt-1 h-4 w-14 shrink-0').attr('aria-hidden', 'true'));
    } else if (servidor?.valida) {
        $cabecera.append(el('span', 'num shrink-0 text-[0.9375rem] font-semibold', soles(servidor.subtotal)));
    }

    $fila.append($cabecera);

    if (local.detalle) {
        $fila.append(el('p', 'mt-0.5 text-[0.8125rem] text-muted', local.detalle));
    }

    if (linea.observacion) {
        $fila.append(el('p', 'mt-0.5 text-[0.8125rem] text-muted', `Nota: ${linea.observacion}`));
    }

    (servidor?.errores ?? []).forEach((error) => {
        $fila.append(el('p', 'mt-1 text-[0.8125rem] font-medium text-danger-ink', error));
    });

    const $acciones = el('div', 'mt-2 flex items-center justify-between gap-3');
    const $cantidad = el('div', 'flex items-center gap-1').attr({ role: 'group', 'aria-label': `Cantidad de ${local.nombre}` });

    $cantidad.append(
        el('button', 'vitrina-paso press', '−').attr({ type: 'button', 'aria-label': 'Quitar uno', 'data-linea-menos': indice }).prop('disabled', linea.cantidad <= 1),
        el('span', 'num w-8 text-center text-sm font-semibold', linea.cantidad),
        el('button', 'vitrina-paso press', '+').attr({ type: 'button', 'aria-label': 'Agregar uno', 'data-linea-mas': indice }).prop('disabled', linea.cantidad >= datos.cantidadMaxima),
    );

    $acciones.append(
        $cantidad,
        el('button', 'press min-h-10 cursor-pointer rounded-control px-2 text-[0.8125rem] font-medium text-danger-ink', 'Quitar').attr({
            type: 'button',
            'data-linea-quitar': indice,
            'aria-label': `Quitar ${local.nombre}`,
        }),
    );

    return $fila.append($acciones);
}

const ATRIBUTOS_LINEA = ['data-linea-menos', 'data-linea-mas', 'data-linea-quitar'];

/** Por qué todavía no se puede continuar. Vacío si no hay impedimento. */
function notaContinuar() {
    if (!datos.aceptaPedidos) {
        return 'Este local no recibe pedidos por ahora.';
    }

    if (!datos.abierto) {
        return 'Podrás hacer tu pedido cuando el local abra.';
    }

    if (carrito.estado !== 'listo') {
        return '';
    }

    if (carrito.respuesta.lineas.some((linea) => !linea.valida)) {
        return 'Corrige o quita los productos marcados para continuar.';
    }

    if (aCentimos(carrito.respuesta.subtotal) < aCentimos(datos.pedidoMinimo)) {
        return `El pedido mínimo en este local es ${soles(datos.pedidoMinimo)}.`;
    }

    return '';
}

function pintarCarrito() {
    const hayLineas = carrito.lineas.length > 0;
    const unidades = carrito.lineas.reduce((total, linea) => total + linea.cantidad, 0);
    const total =
        carrito.estado === 'listo' ? soles(carrito.respuesta.subtotal) : carrito.estado === 'error' ? 'Sin confirmar' : 'Calculando…';

    // Repintar las líneas no debe sacar el foco del botón que se estaba usando.
    const activo = document.activeElement;
    const atributoActivo = ATRIBUTOS_LINEA.find((atributo) => activo?.hasAttribute?.(atributo));
    const indiceActivo = atributoActivo ? activo.getAttribute(atributoActivo) : null;

    $('[data-carrito-barra]').prop('hidden', !hayLineas);
    $('[data-carrito-cantidad]').text(unidades);
    $('[data-carrito-total]').text(total);
    $('[data-carrito-abrir]').attr('aria-label', `Ver carrito: ${unidades} ${unidades === 1 ? 'producto' : 'productos'}, ${total}`);

    $('[data-carrito-vacio]').prop('hidden', hayLineas);
    $('[data-carrito-pie]').prop('hidden', !hayLineas);
    $('[data-carrito-vaciar]').prop('hidden', !hayLineas);
    $('[data-carrito-error]').prop('hidden', carrito.estado !== 'error');
    $('[data-carrito-error-texto]').text(carrito.error);

    if (carrito.estado === 'cargando') {
        $('[data-carrito-subtotal]')
            .empty()
            .append(el('span', 'skeleton inline-block h-5 w-20 align-middle').attr('aria-hidden', 'true'), el('span', 'sr-only', 'Calculando'));
    } else {
        $('[data-carrito-subtotal]').text(carrito.estado === 'listo' ? soles(carrito.respuesta.subtotal) : 'Sin confirmar');
    }

    $('[data-carrito-lineas]').empty().append(carrito.lineas.map(pintarLinea));

    const nota = notaContinuar();

    $('[data-carrito-continuar]').prop('disabled', carrito.estado !== 'listo' || nota !== '');
    $('[data-carrito-nota]').text(nota).prop('hidden', nota === '');

    if (atributoActivo) {
        const $destino = $(`[${atributoActivo}="${indiceActivo}"]:not(:disabled)`);

        ($destino.length ? $destino : $('#hoja-carrito [data-hoja-cerrar]').first()).trigger('focus');
    }
}

function initCarrito() {
    const hoja = document.getElementById('hoja-carrito');

    $('[data-carrito-abrir]').on('click', () => {
        abrirHoja(hoja);

        // Los precios pudieron cambiar desde la última consulta.
        reprogramarValidacion();
    });

    $('[data-carrito-reintentar]').on('click', validarCarrito);

    const cambiar = (atributo, accion) => {
        $('[data-carrito-lineas]').on('click', `[${atributo}]`, function () {
            accion(Number($(this).attr(atributo)));
            cambioEnCarrito();
        });
    };

    cambiar('data-linea-menos', (i) => {
        carrito.lineas[i].cantidad = Math.max(1, carrito.lineas[i].cantidad - 1);
    });
    cambiar('data-linea-mas', (i) => {
        carrito.lineas[i].cantidad = Math.min(datos.cantidadMaxima, carrito.lineas[i].cantidad + 1);
    });
    cambiar('data-linea-quitar', (i) => {
        carrito.lineas.splice(i, 1);
    });

    // Vaciar pide un segundo toque: no se puede deshacer.
    let confirmando = null;
    const $vaciar = $('[data-carrito-vaciar]');

    function restaurarVaciar() {
        clearTimeout(confirmando);
        confirmando = null;
        $vaciar.text('Vaciar');
    }

    $vaciar.on('click', () => {
        if (!confirmando) {
            $vaciar.text('¿Vaciar todo?');
            confirmando = setTimeout(restaurarVaciar, ESPERA_CONFIRMAR_VACIAR_MS);
            return;
        }

        restaurarVaciar();
        carrito.lineas = [];
        cambioEnCarrito();
    });

    pintarCarrito();

    if (carrito.lineas.length) {
        validarCarrito();
    }
}

/* -------------------------------------------------------------------------- */
/* Finalizar pedido                                                            */
/* -------------------------------------------------------------------------- */

function initFinalizar() {
    const hoja = document.getElementById('hoja-finalizar');
    const $hoja = $(hoja);
    const $form = $hoja.find('[data-finalizar-form]');
    const $enviar = $hoja.find('[data-finalizar-enviar]');
    const valor = (nombre) => $form.find(`[name="${nombre}"]`).val();
    const tipoElegido = () => $form.find('[data-tipo]:checked').val();

    // La clave dura mientras se reenvíe lo mismo: un reintento no crea otro pedido.
    let intento = { firma: null, clave: null };
    let enviando = false;

    function pintarResumen() {
        const esDelivery = tipoElegido() === 'delivery';
        const subtotal = aCentimos(carrito.respuesta?.subtotal ?? 0);
        const delivery = esDelivery ? aCentimos(datos.costoDelivery) : 0;

        $form.find('[data-solo-delivery]').prop('hidden', !esDelivery);
        $hoja.find('[data-resumen-delivery-fila]').prop('hidden', !esDelivery);
        $hoja.find('[data-resumen-subtotal]').text(solesDeCentimos(subtotal));
        $hoja.find('[data-resumen-delivery]').text(delivery === 0 ? 'Gratis' : solesDeCentimos(delivery));
        $hoja.find('[data-resumen-total]').text(solesDeCentimos(subtotal + delivery));
    }

    function pintarPago() {
        const $metodo = $form.find('[data-metodo]:checked');

        $form.find('[data-pago-detalle]').each(function () {
            $(this).prop('hidden', this.dataset.pagoDetalle !== $metodo.val());
        });
        $form.find('[data-pago-referencia]').prop('hidden', $metodo.attr('data-por-verificar') !== '1');
    }

    function limpiarErrores() {
        $form.find('[data-error]').text('').prop('hidden', true);
        $form.find('[aria-invalid]').removeAttr('aria-invalid');
        $hoja.find('[data-finalizar-error]').prop('hidden', true);
        $hoja.find('[data-finalizar-reintentar-caja]').prop('hidden', true);
    }

    function errorGeneral(mensaje, conReintento = false) {
        $hoja.find('[data-finalizar-error-texto]').text(mensaje);
        $hoja.find('[data-finalizar-reintentar-caja]').prop('hidden', !conReintento);
        $hoja.find('[data-finalizar-error]').prop('hidden', false);
        $hoja.find('[data-finalizar-cuerpo]').scrollTop(0);
        anunciar(mensaje);
    }

    function pintarErrores(errores) {
        const generales = [];
        let primero = null;

        Object.entries(errores).forEach(([campo, mensajes]) => {
            const $error = $form.find('[data-error]').filter((_, elemento) => elemento.dataset.error === campo);

            if (!$error.length) {
                generales.push(mensajes[0]);
                return;
            }

            $error.text(mensajes[0]).prop('hidden', false);
            $form.find(`[name="${campo}"]`).attr('aria-invalid', 'true');
            primero ??= $error[0];
        });

        if (generales.length) {
            errorGeneral(generales.join(' '));
        } else if (primero) {
            primero.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    function estadoEnviando(activo) {
        enviando = activo;
        $enviar.prop('disabled', activo);
        $hoja.find('[data-finalizar-spinner]').prop('hidden', !activo);
        $hoja.find('[data-finalizar-etiqueta]').text(activo ? 'Enviando…' : 'Confirmar pedido');
    }

    function datosPedido() {
        return {
            nombre: valor('nombre'),
            telefono: valor('telefono'),
            tipo: tipoElegido() ?? null,
            direccion: valor('direccion'),
            referencia: valor('referencia'),
            metodo_pago_id: $form.find('[data-metodo]:checked').val() ?? null,
            referencia_pago: valor('referencia_pago'),
            acepta_privacidad: $form.find('[data-privacidad]').prop('checked'),
            sitio_web: $form.find('[data-trampa]').val(),
            lineas: carrito.lineas.map(({ producto_id, variante_id, opciones, cantidad, observacion }) => ({ producto_id, variante_id, opciones, cantidad, observacion })),
        };
    }

    function exito(respuesta) {
        carrito.lineas = [];
        guardarCarrito();
        almacen.escribir(CLAVE_MI_PEDIDO, respuesta.codigo);
        window.location.assign(respuesta.url);
    }

    function fallo(xhr) {
        estadoEnviando(false);

        if (xhr.status === 422) {
            pintarErrores(xhr.responseJSON?.errors ?? {});
            return;
        }

        if (xhr.status === 429) {
            errorGeneral(xhr.responseJSON?.message || 'Se enviaron muchos pedidos seguidos. Espera un momento e inténtalo otra vez.', true);
            return;
        }

        if (xhr.status === 409) {
            errorGeneral(xhr.responseJSON?.message || 'Este restaurante no está disponible por ahora.', false);
            return;
        }

        errorGeneral('No pudimos enviar tu pedido. Revisa tu conexión e inténtalo otra vez; no se creará un pedido repetido.', true);
    }

    function enviar() {
        if (enviando) {
            return;
        }

        limpiarErrores();

        const pedido = datosPedido();
        const firma = JSON.stringify(pedido);

        if (intento.firma !== firma) {
            intento = { firma, clave: nuevaClaveIdempotencia() };
        }

        estadoEnviando(true);

        $.ajax({
            url: datos.urlPedido,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ ...pedido, idempotency_key: intento.clave }),
            dataType: 'json',
            headers: { Accept: 'application/json' },
            timeout: TIEMPO_MAXIMO_PEDIDO_MS,
        })
            .done(exito)
            .fail(fallo);
    }

    $('[data-carrito-continuar]').on('click', () => {
        document.getElementById('hoja-carrito').close();
        limpiarErrores();
        pintarResumen();
        pintarPago();
        abrirHoja(hoja);
    });

    $hoja.on('click', '[data-finalizar-volver]', () => {
        hoja.close();
        abrirHoja(document.getElementById('hoja-carrito'));
        reprogramarValidacion();
    });

    $form.on('change', '[data-tipo]', pintarResumen);
    $form.on('change', '[data-metodo]', pintarPago);

    // El error de un campo desaparece en cuanto la persona lo corrige.
    $form.on('input change', '[name]', function () {
        $(this).removeAttr('aria-invalid');
        $form
            .find('[data-error]')
            .filter((_, elemento) => elemento.dataset.error === this.name)
            .text('')
            .prop('hidden', true);
    });
    $form.on('submit', (evento) => {
        evento.preventDefault();
        enviar();
    });
    $hoja.on('click', '[data-finalizar-reintentar]', enviar);
}

function initMiPedido() {
    const codigo = almacen.leer(CLAVE_MI_PEDIDO);

    if (codigo && /^[A-Za-z0-9]{32}$/.test(codigo)) {
        $('[data-mi-pedido]').attr('href', `${datos.urlPedidos}/${codigo}`).prop('hidden', false);
    }
}


/* -------------------------------------------------------------------------- */
/* Detalle de producto: la línea armada se suma al carrito                     */
/* -------------------------------------------------------------------------- */

function agregarAlCarrito(linea, producto) {
    const existente = carrito.lineas.find((l) => claveLinea(l) === claveLinea(linea));

    if (existente) {
        existente.cantidad = Math.min(datos.cantidadMaxima, existente.cantidad + linea.cantidad);
    } else if (carrito.lineas.length >= datos.lineasMaximas) {
        return `Tu carrito admite hasta ${datos.lineasMaximas} productos distintos.`;
    } else {
        carrito.lineas.push(linea);
    }

    cambioEnCarrito();
    anunciar(`${producto.nombre} se agregó al carrito.`);

    return null;
}

$(() => {
    initBusqueda();
    initChips();
    initHojas();
    initDetalle({
        hoja: document.getElementById('hoja-producto'),
        productos: datos.productos,
        cantidadMaxima: datos.cantidadMaxima,
        alAgregar: agregarAlCarrito,
    });
    initCarrito();
    initFinalizar();
    initMiPedido();
});
