/**
 * FOORA: vitrina pública (jQuery). Entrada propia: no carga el JS del panel en el celular.
 *
 * - Carta: búsqueda y chips de categorías.
 * - Hojas: bottom sheet en celular y modal en escritorio, con arrastre para cerrar.
 * - Detalle de producto: variante, grupos de opciones, cantidad, observaciones y total en vivo.
 * - Carrito: se guarda en localStorage solo con ids, cantidades y observaciones. Los precios del
 *   carrito siempre vienen del servidor.
 * - Finalizar pedido: datos, método de pago y envío. El servidor recalcula todo.
 *
 * Los nombres y descripciones los escribe cada restaurante: todo dato se pinta con .text() o
 * .val(), nunca con .html() ni concatenado en una plantilla.
 */
import $ from 'jquery';

const datos = window.FOORA_VITRINA;

const ESPERA_VALIDAR_MS = 400;
const TIEMPO_MAXIMO_VALIDAR_MS = 15000;
const OBSERVACION_MAXIMA = 200;
const CLAVE_CARRITO = `foora.carrito.v1.${datos.slug}`;
const VELOCIDAD_CIERRE = 0.11;
const ESPERA_CONFIRMAR_VACIAR_MS = 3000;
const TIEMPO_MAXIMO_PEDIDO_MS = 20000;
const CLAVE_MI_PEDIDO = `foora.pedido.v1.${datos.slug}`;

const aCentimos = (monto) => Math.round(Number(monto) * 100);
const ESPACIO_FIJO = String.fromCharCode(160);
const soles = (monto) => `S/${ESPACIO_FIJO}${Number(monto).toFixed(2)}`;
const solesDeCentimos = (centimos) => soles(centimos / 100);
const sinTildes = (texto) =>
    String(texto)
        .normalize('NFD')
        .replace(/\p{Mn}/gu, '')
        .toLowerCase();

/** Crea un elemento con clases y texto. El texto siempre se asigna con .text(). */
function el(etiqueta, clases = '', texto = null) {
    const $elemento = $(document.createElement(etiqueta)).addClass(clases);

    return texto === null ? $elemento : $elemento.text(texto);
}

function anunciar(mensaje) {
    $('[data-anuncio]').text(mensaje);
}

/* -------------------------------------------------------------------------- */
/* Carta: búsqueda y chips                                                     */
/* -------------------------------------------------------------------------- */

function initBusqueda() {
    const $buscar = $('[data-buscar]');
    const $items = $('[data-producto-item]');
    const $vacio = $('[data-sin-resultados]');

    $items.each(function () {
        $(this).data('busqueda', sinTildes($(this).attr('data-nombre')));
    });

    $buscar.on('input', function () {
        const termino = sinTildes(this.value.trim());
        let visibles = 0;

        $items.each(function () {
            const coincide = $(this).data('busqueda').includes(termino);
            $(this).prop('hidden', !coincide);
            visibles += coincide ? 1 : 0;
        });

        $('[data-subseccion], [data-seccion]').each(function () {
            $(this).prop('hidden', $(this).find('[data-producto-item]:not([hidden])').length === 0);
        });

        $('[data-chips]').prop('hidden', termino !== '');
        $vacio.prop('hidden', visibles > 0);
        $vacio.find('[data-sin-resultados-texto]').text(this.value.trim());
    });
}

function initChips() {
    const $chips = $('[data-chip]');

    if (!$chips.length) {
        return;
    }

    function activar(id) {
        const $activo = $chips.filter(`[data-chip="${id}"]`);

        $chips.removeAttr('aria-current');
        $activo.attr('aria-current', 'true');
        $('[data-subchips]').each(function () {
            $(this).prop('hidden', String($(this).data('subchips')) !== String(id));
        });

        // Solo se mueve la fila de chips: scrollIntoView arrastraría también la página.
        const fila = $activo.closest('ul')[0];
        const chip = $activo[0];

        if (fila && chip) {
            fila.scrollTo({ left: chip.offsetLeft - fila.offsetLeft - 16, behavior: 'smooth' });
        }
    }

    activar($chips.first().data('chip'));

    if (!('IntersectionObserver' in window)) {
        return;
    }

    const observador = new IntersectionObserver(
        (entradas) => {
            entradas.filter((entrada) => entrada.isIntersecting).forEach((entrada) => activar($(entrada.target).data('seccion')));
        },
        { rootMargin: '-96px 0px -70% 0px' },
    );

    $('[data-seccion]').each(function () {
        observador.observe(this);
    });
}

/* -------------------------------------------------------------------------- */
/* Hojas: bottom sheet en celular, modal en escritorio                         */
/* -------------------------------------------------------------------------- */

function abrirHoja(hoja) {
    if (typeof hoja.showModal !== 'function' || hoja.open) {
        return;
    }

    hoja.showModal();
    $('html').addClass('overflow-hidden');
}

function initHojas() {
    $(document).on('click', '[data-hoja-cerrar]', function () {
        $(this).closest('dialog')[0].close();
    });

    // Clic en el fondo (fuera de la caja del diálogo) cierra.
    $(document).on('click', 'dialog[data-hoja]', function (evento) {
        if (evento.target !== this) {
            return;
        }

        const caja = this.getBoundingClientRect();
        const dentro =
            evento.clientX >= caja.left && evento.clientX <= caja.right && evento.clientY >= caja.top && evento.clientY <= caja.bottom;

        if (!dentro) {
            this.close();
        }
    });

    $('dialog[data-hoja]').on('close', () => {
        if (!$('dialog[data-hoja][open]').length) {
            $('html').removeClass('overflow-hidden');
        }
    });

    $('[data-hoja-asa]').each(function () {
        initArrastre(this);
    });
}

/** Arrastrar hacia abajo cierra. Basta un gesto rápido, sin importar la distancia. */
function initArrastre(asa) {
    const hoja = asa.closest('dialog');
    const esHojaInferior = window.matchMedia('(max-width: 639px)');
    let inicio = null;

    asa.addEventListener('pointerdown', (evento) => {
        if (!esHojaInferior.matches || inicio || evento.target.closest('button')) {
            return;
        }

        inicio = { y: evento.clientY, tiempo: performance.now(), puntero: evento.pointerId };
        asa.setPointerCapture(evento.pointerId);
        hoja.style.transition = 'none';
    });

    asa.addEventListener('pointermove', (evento) => {
        if (!inicio || evento.pointerId !== inicio.puntero) {
            return;
        }

        const distancia = evento.clientY - inicio.y;

        // Hacia arriba hay fricción en vez de un tope seco.
        hoja.style.transform = `translateY(${distancia > 0 ? distancia : distancia * 0.15}px)`;
    });

    function soltar(evento) {
        if (!inicio || evento.pointerId !== inicio.puntero) {
            return;
        }

        const distancia = evento.clientY - inicio.y;
        const velocidad = Math.abs(distancia) / (performance.now() - inicio.tiempo);
        const cerrar = distancia > 0 && (distancia > hoja.offsetHeight * 0.3 || velocidad > VELOCIDAD_CIERRE);

        inicio = null;
        hoja.style.transition = '';
        hoja.style.transform = '';

        if (cerrar) {
            hoja.close();
        }
    }

    asa.addEventListener('pointerup', soltar);
    asa.addEventListener('pointercancel', soltar);
}

/* -------------------------------------------------------------------------- */
/* Carrito: estado y almacenamiento                                            */
/* -------------------------------------------------------------------------- */

/** localStorage puede no existir o lanzar error (modo privado, bloqueado): se sigue en memoria. */
const almacen = {
    leer(clave = CLAVE_CARRITO) {
        try {
            return window.localStorage.getItem(clave);
        } catch {
            return null;
        }
    },
    escribir(valor, clave = CLAVE_CARRITO) {
        try {
            window.localStorage.setItem(clave, valor);
        } catch {
            // Sin almacenamiento el carrito dura lo que dure la página.
        }
    },
};

const esIdValido = (valor) => Number.isInteger(valor) && valor > 0;

/** Descarta lo que no tenga la forma esperada: el contenido guardado no es de confianza. */
function sanearLineas(valor) {
    if (!valor || !Array.isArray(valor.lineas)) {
        return [];
    }

    return valor.lineas
        .filter((linea) => linea && esIdValido(linea.producto_id) && Number.isInteger(linea.cantidad) && linea.cantidad >= 1)
        .slice(0, datos.lineasMaximas)
        .map((linea) => ({
            producto_id: linea.producto_id,
            variante_id: esIdValido(linea.variante_id) ? linea.variante_id : null,
            opciones: Array.isArray(linea.opciones) ? [...new Set(linea.opciones.filter(esIdValido))] : [],
            cantidad: Math.min(linea.cantidad, datos.cantidadMaxima),
            observacion: typeof linea.observacion === 'string' ? linea.observacion.slice(0, OBSERVACION_MAXIMA) : '',
        }));
}

function leerCarrito() {
    try {
        return sanearLineas(JSON.parse(almacen.leer()));
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

function claveLinea(linea) {
    return JSON.stringify([linea.producto_id, linea.variante_id, [...linea.opciones].sort((a, b) => a - b), linea.observacion]);
}

function guardarCarrito() {
    almacen.escribir(JSON.stringify({ lineas: carrito.lineas }));
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

function textoLineaLocal(linea) {
    const producto = datos.productos[linea.producto_id];

    if (!producto) {
        return { nombre: 'Producto no disponible', detalle: '' };
    }

    const variante = producto.variantes.find((v) => v.id === linea.variante_id);
    const opciones = producto.grupos.flatMap((g) => g.opciones).filter((o) => linea.opciones.includes(o.id));

    return {
        nombre: producto.nombre,
        detalle: [variante?.nombre, ...opciones.map((o) => o.nombre)].filter(Boolean).join(' · '),
    };
}

function pintarLinea(linea, indice) {
    const servidor = carrito.respuesta?.lineas[indice];
    const local = textoLineaLocal(linea);
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

/** crypto.randomUUID solo existe en HTTPS; getRandomValues también funciona en HTTP. */
function nuevaClaveIdempotencia() {
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));

    return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

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
        almacen.escribir(respuesta.codigo, CLAVE_MI_PEDIDO);
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
/* Detalle de producto                                                         */
/* -------------------------------------------------------------------------- */

const detalle = { producto: null, varianteId: null, opciones: new Set(), cantidad: 1, mostrarErrores: false };

const $detalle = () => $('#hoja-producto');

function elegidasDe(grupo) {
    return grupo.opciones.filter((opcion) => detalle.opciones.has(opcion.id));
}

function gruposSinCumplir() {
    return detalle.producto.grupos.filter((grupo) => elegidasDe(grupo).length < grupo.minimo);
}

function totalDetalleCentimos() {
    const { producto, varianteId, opciones, cantidad } = detalle;
    const variante = producto.variantes.find((v) => v.id === varianteId);

    if (producto.tipo === 'variantes' && !variante) {
        return null;
    }

    const extras = producto.grupos
        .flatMap((grupo) => grupo.opciones)
        .filter((opcion) => opciones.has(opcion.id))
        .reduce((suma, opcion) => suma + aCentimos(opcion.precio_extra), 0);

    return (aCentimos(variante?.precio ?? producto.precio) + extras) * cantidad;
}

function pintarTotalDetalle() {
    const $hoja = $detalle();
    const total = totalDetalleCentimos();

    $hoja.find('[data-detalle-cantidad]').text(detalle.cantidad);
    $hoja.find('[data-detalle-menos]').prop('disabled', detalle.cantidad <= 1);
    $hoja.find('[data-detalle-mas]').prop('disabled', detalle.cantidad >= datos.cantidadMaxima);
    $hoja.find('[data-detalle-total]').text(total === null ? '' : solesDeCentimos(total));
}

/** Título de bloque con su regla: nombre a la izquierda, regla a la derecha. */
function cabeceraBloque(titulo, regla, obligatorio) {
    const $cabecera = el('legend', 'flex w-full items-baseline justify-between gap-3 text-sm font-semibold');

    $cabecera.append(el('span', 'min-w-0', titulo));
    $cabecera.append(el('span', `shrink-0 text-xs font-medium ${obligatorio ? 'text-brand-700' : 'text-muted'}`, regla));

    return $cabecera;
}

function filaElegible({ tipo, nombre, valor, marcado, agotado, texto, detalleTexto, atributo }) {
    const $fila = el('label', `flex min-h-12 items-center gap-3 border-b border-rule last:border-b-0 ${agotado ? 'cursor-not-allowed text-faint' : 'cursor-pointer'}`);
    const $control = el('input', 'size-[1.125rem] shrink-0 accent-brand-700')
        .attr({ type: tipo, name: nombre, value: valor, [atributo]: valor })
        .prop({ checked: marcado, disabled: agotado });

    return $fila.append($control, el('span', 'min-w-0 flex-1 text-sm', texto), el('span', 'num shrink-0 text-[0.8125rem] text-muted', detalleTexto));
}

function errorDeBloque() {
    return el('p', 'mt-1 text-[0.8125rem] font-medium text-danger-ink').attr('data-bloque-error', '').prop('hidden', true);
}

function pintarVariantes() {
    const { producto } = detalle;
    const $contenedor = $detalle().find('[data-detalle-variantes]').empty();

    if (producto.tipo !== 'variantes') {
        return;
    }

    const $bloque = el('fieldset', 'mt-5').attr('data-bloque', 'variante');

    $bloque.append(cabeceraBloque('Elige una opción', 'Obligatorio', true));
    producto.variantes.forEach((variante) => {
        $bloque.append(
            filaElegible({
                tipo: 'radio',
                nombre: 'detalle-variante',
                valor: variante.id,
                marcado: false,
                agotado: !variante.disponible,
                atributo: 'data-variante',
                texto: variante.nombre,
                detalleTexto: variante.disponible ? soles(variante.precio) : 'Agotado',
            }),
        );
    });

    $contenedor.append($bloque.append(errorDeBloque()));
}

function pintarGrupos() {
    const $contenedor = $detalle().find('[data-detalle-grupos]').empty();

    detalle.producto.grupos.forEach((grupo) => {
        const $bloque = el('fieldset', 'mt-5').attr('data-bloque', grupo.id);

        $bloque.append(cabeceraBloque(grupo.nombre, grupo.obligatorio ? `${grupo.regla} · Obligatorio` : grupo.regla, grupo.obligatorio));

        grupo.opciones.forEach((opcion) => {
            const costo = aCentimos(opcion.precio_extra);

            $bloque.append(
                filaElegible({
                    // Un radio no se puede desmarcar: en un grupo opcional se usa casilla.
                    tipo: grupo.maximo === 1 && grupo.obligatorio ? 'radio' : 'checkbox',
                    nombre: `detalle-grupo-${grupo.id}`,
                    valor: opcion.id,
                    marcado: false,
                    agotado: !opcion.disponible,
                    atributo: 'data-opcion',
                    texto: opcion.nombre,
                    detalleTexto: !opcion.disponible ? 'Agotado' : costo > 0 ? `+${solesDeCentimos(costo)}` : '',
                }).attr('data-grupo', grupo.id),
            );
        });

        $contenedor.append($bloque.append(errorDeBloque()));
    });
}

function mostrarErrorBloque(id, mensaje) {
    $detalle().find(`[data-bloque="${id}"] [data-bloque-error]`).text(mensaje ?? '').prop('hidden', !mensaje);
}

function abrirDetalle(id) {
    const producto = datos.productos[id];

    if (!producto) {
        return;
    }

    Object.assign(detalle, { producto, varianteId: null, opciones: new Set(), cantidad: 1 });

    const $hoja = $detalle();
    const personas = producto.personas ? `Para ${producto.personas} ${producto.personas === 1 ? 'persona' : 'personas'}` : '';

    $hoja.find('[data-detalle-nombre]').text(producto.nombre);
    $hoja.find('[data-detalle-descripcion]').text(producto.descripcion ?? '').prop('hidden', !producto.descripcion);
    $hoja.find('[data-detalle-meta]').text([personas, ...producto.etiquetas].filter(Boolean).join(' · '));
    $hoja.find('[data-detalle-imagen]').attr('src', producto.imagen ?? '').prop('hidden', !producto.imagen);
    $hoja.find('[data-detalle-observacion]').val('');
    $hoja.find('[data-detalle-contador]').text(`0/${OBSERVACION_MAXIMA}`);
    $hoja.find('[data-detalle-error]').prop('hidden', true).text('');

    pintarVariantes();
    pintarGrupos();
    pintarTotalDetalle();

    abrirHoja($hoja[0]);
    $hoja.find('[data-detalle-cuerpo]').scrollTop(0);
}

function cambiarOpcion(control) {
    const grupo = detalle.producto.grupos.find((g) => String(g.id) === $(control).closest('label').attr('data-grupo'));
    const id = Number(control.value);

    mostrarErrorBloque(grupo.id, null);

    if (!control.checked) {
        detalle.opciones.delete(id);
        return;
    }

    if (grupo.maximo === 1) {
        elegidasDe(grupo).forEach((opcion) => detalle.opciones.delete(opcion.id));
        $(control).closest('fieldset').find('input[data-opcion]').not(control).prop('checked', false);
    } else if (grupo.maximo !== null && elegidasDe(grupo).length >= grupo.maximo) {
        control.checked = false;
        mostrarErrorBloque(grupo.id, `Solo puedes elegir hasta ${grupo.maximo}.`);
        return;
    }

    detalle.opciones.add(id);
}

function agregarDetalle() {
    const { producto, varianteId, opciones, cantidad } = detalle;
    const $hoja = $detalle();
    const faltaVariante = producto.tipo === 'variantes' && !varianteId;
    const faltantes = gruposSinCumplir();

    if (faltaVariante || faltantes.length) {
        if (faltaVariante) {
            mostrarErrorBloque('variante', 'Elige una opción para continuar.');
        }

        faltantes.forEach((grupo) => mostrarErrorBloque(grupo.id, `${grupo.regla} para continuar.`));
        $hoja.find(`[data-bloque="${faltaVariante ? 'variante' : faltantes[0].id}"]`)[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        return;
    }

    const linea = {
        producto_id: producto.id,
        variante_id: varianteId,
        opciones: [...opciones],
        cantidad,
        observacion: $hoja.find('[data-detalle-observacion]').val().trim().slice(0, OBSERVACION_MAXIMA),
    };

    const existente = carrito.lineas.find((l) => claveLinea(l) === claveLinea(linea));

    if (existente) {
        existente.cantidad = Math.min(datos.cantidadMaxima, existente.cantidad + cantidad);
    } else if (carrito.lineas.length >= datos.lineasMaximas) {
        $hoja.find('[data-detalle-error]').text(`Tu carrito admite hasta ${datos.lineasMaximas} productos distintos.`).prop('hidden', false);
        return;
    } else {
        carrito.lineas.push(linea);
    }

    cambioEnCarrito();
    anunciar(`${producto.nombre} se agregó al carrito.`);
    $hoja[0].close();
}

function initDetalle() {
    const $hoja = $detalle();

    $(document).on('click', '[data-producto]', function () {
        abrirDetalle(Number($(this).attr('data-producto')));
    });

    $hoja.on('change', 'input[data-variante]', function () {
        detalle.varianteId = Number(this.value);
        mostrarErrorBloque('variante', null);
        pintarTotalDetalle();
    });

    $hoja.on('change', 'input[data-opcion]', function () {
        cambiarOpcion(this);
        pintarTotalDetalle();
    });

    $hoja.on('click', '[data-detalle-menos]', () => {
        detalle.cantidad = Math.max(1, detalle.cantidad - 1);
        pintarTotalDetalle();
    });

    $hoja.on('click', '[data-detalle-mas]', () => {
        detalle.cantidad = Math.min(datos.cantidadMaxima, detalle.cantidad + 1);
        pintarTotalDetalle();
    });

    $hoja.on('input', '[data-detalle-observacion]', function () {
        $hoja.find('[data-detalle-contador]').text(`${this.value.length}/${OBSERVACION_MAXIMA}`);
    });

    $hoja.on('click', '[data-detalle-agregar]', agregarDetalle);
}

$(() => {
    initBusqueda();
    initChips();
    initHojas();
    initDetalle();
    initCarrito();
    initFinalizar();
    initMiPedido();
});
