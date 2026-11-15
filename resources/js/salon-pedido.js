import $ from 'jquery';
import { initBusqueda, initChips } from './carta/busqueda';
import { initDetalle } from './carta/detalle';
import { abrirHoja, initHojas } from './carta/hojas';
import { almacen, claveLinea, el, nuevaClaveIdempotencia, sanearLineas, soles, textoLineaLocal } from './carta/util';

/**
 * Toma de pedido del salón (panel/mesas/cuentas/{cuenta}/pedido).
 *
 * - Carta, búsqueda, chips y detalle de producto: los mismos módulos que la vitrina (resources/js/carta/).
 * - Borrador por cuenta en localStorage: ids, cantidades, opciones y nota de cada línea. Nunca precios:
 *   el total lo calcula el servidor cada vez que el borrador cambia.
 * - Enviar a cocina lleva una clave de idempotencia que se conserva mientras el borrador no cambie:
 *   si el wifi falla y el mesero vuelve a tocar, el servidor devuelve la misma ronda.
 * - Si el servidor rechaza una línea (agotada o cambiada), se marca esa línea y el resto se conserva.
 */

const ESPERA_VALIDAR_MS = 400;
const TIEMPO_MAXIMO_MS = 15000;

function initTomaPedido() {
    if (!$('[data-toma-pedido]').length || !window.FOORA_SALON) {
        return;
    }

    const datos = window.FOORA_SALON;
    const CLAVE = `foora.salon.v1.${datos.cuentaId}`;
    const limites = { lineasMaximas: datos.lineasMaximas, cantidadMaxima: datos.cantidadMaxima };
    const hoja = document.getElementById('hoja-borrador');
    const $hoja = $(hoja);

    function leer() {
        try {
            const guardado = JSON.parse(almacen.leer(CLAVE));

            return {
                lineas: sanearLineas(guardado, limites),
                clave: typeof guardado?.clave === 'string' && /^[a-f0-9]{32}$/.test(guardado.clave) ? guardado.clave : null,
            };
        } catch {
            return { lineas: [], clave: null };
        }
    }

    const guardado = leer();
    const borrador = {
        lineas: guardado.lineas,
        // La clave vale para este borrador exacto: cualquier cambio la descarta.
        clave: guardado.clave,
        respuesta: null,
        estado: 'inactivo',
        error: '',
        erroresServidor: {},
        espera: null,
        peticion: null,
        enviando: false,
    };

    const anunciar = (mensaje) => $('[data-anuncio]').text(mensaje);

    function guardar() {
        almacen.escribir(CLAVE, JSON.stringify({ lineas: borrador.lineas, clave: borrador.clave }));
    }

    function cambio() {
        borrador.clave = null;
        borrador.erroresServidor = {};
        guardar();
        reprogramar();
    }

    function reprogramar() {
        borrador.peticion?.abort();
        borrador.peticion = null;
        borrador.respuesta = null;
        clearTimeout(borrador.espera);
        borrador.estado = borrador.lineas.length ? 'cargando' : 'inactivo';

        if (borrador.lineas.length) {
            borrador.espera = setTimeout(validar, ESPERA_VALIDAR_MS);
        }

        pintar();
    }

    const paraServidor = () =>
        borrador.lineas.map(({ producto_id, variante_id, opciones, cantidad, observacion }) => ({ producto_id, variante_id, opciones, cantidad, observacion }));

    function validar() {
        borrador.peticion?.abort();
        borrador.estado = 'cargando';
        pintar();

        const peticion = $.ajax({
            url: datos.urlValidar,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ lineas: paraServidor() }),
            dataType: 'json',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            timeout: TIEMPO_MAXIMO_MS,
        });

        borrador.peticion = peticion;

        peticion
            .done((respuesta) => {
                borrador.respuesta = respuesta;
                borrador.estado = 'listo';
                borrador.error = '';
            })
            .fail((xhr, estado) => {
                if (estado === 'abort') {
                    return;
                }

                borrador.estado = 'error';
                borrador.error = xhr.status === 422 ? xhr.responseJSON?.message || 'Revisa la ronda.' : 'No pudimos calcular el total. Revisa tu conexión.';
            })
            .always(() => {
                if (borrador.peticion === peticion) {
                    borrador.peticion = null;
                    pintar();
                }
            });
    }

    function pintarLinea(linea, indice) {
        const servidor = borrador.respuesta?.lineas[indice];
        const local = textoLineaLocal(datos.productos, linea);
        const errores = [...(servidor?.errores ?? []), ...(borrador.erroresServidor[indice] ?? [])];
        const $fila = el('li', `py-3 ${errores.length ? 'rounded-control bg-danger-bg/40 px-2' : ''}`).attr('data-linea', indice);

        const $cabecera = el('div', 'flex items-start justify-between gap-3');
        $cabecera.append(el('p', 'min-w-0 text-[0.9375rem] font-semibold leading-snug', servidor?.nombre ?? local.nombre));

        if (borrador.estado === 'cargando') {
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

        [...new Set(errores)].forEach((error) => {
            $fila.append(el('p', 'mt-1 text-[0.8125rem] font-medium text-danger-ink', error).attr('data-linea-error', ''));
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

    function hayErrores() {
        return (borrador.respuesta?.lineas ?? []).some((linea) => !linea.valida) || Object.keys(borrador.erroresServidor).length > 0;
    }

    function pintar() {
        const hayLineas = borrador.lineas.length > 0;
        const unidades = borrador.lineas.reduce((total, linea) => total + linea.cantidad, 0);
        const total = borrador.estado === 'listo' ? soles(borrador.respuesta.subtotal) : borrador.estado === 'error' ? 'Sin confirmar' : 'Calculando…';

        $('[data-borrador-barra]').prop('hidden', !hayLineas);
        $('[data-borrador-cantidad]').text(`${unidades} ${unidades === 1 ? 'producto' : 'productos'}`);
        $('[data-borrador-total]').text(total);
        $hoja.find('[data-borrador-vacio]').prop('hidden', hayLineas);
        $hoja.find('[data-borrador-pie]').prop('hidden', !hayLineas);
        $hoja.find('[data-borrador-subtotal]').text(total);
        $hoja.find('[data-borrador-lineas]').empty().append(borrador.lineas.map(pintarLinea));

        const nota = hayErrores() ? 'Corrige o quita los productos marcados para enviar.' : borrador.estado === 'error' ? borrador.error : '';

        $hoja.find('[data-borrador-nota]').text(nota).prop('hidden', nota === '');
        $hoja.find('[data-borrador-enviar]').prop('disabled', borrador.enviando || borrador.estado !== 'listo' || hayErrores());
        $hoja.find('[data-borrador-enviar-texto]').text(borrador.enviando ? 'Enviando…' : 'Enviar a cocina');
    }

    function mostrarError(mensaje) {
        $hoja.find('[data-borrador-error]').text(mensaje).prop('hidden', !mensaje);
        anunciar(mensaje);
    }

    function enviar() {
        if (borrador.enviando || !borrador.lineas.length) {
            return;
        }

        borrador.clave ??= nuevaClaveIdempotencia();
        guardar();
        borrador.enviando = true;
        mostrarError('');
        pintar();

        $.ajax({
            url: datos.urlEnviar,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ lineas: paraServidor(), idempotency_key: borrador.clave }),
            dataType: 'json',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            timeout: TIEMPO_MAXIMO_MS,
        })
            .done((respuesta) => {
                borrador.lineas = [];
                borrador.clave = null;
                guardar();
                window.location.assign(respuesta.url);
            })
            .fail((xhr) => {
                borrador.enviando = false;
                const json = xhr.responseJSON || {};

                if (xhr.status === 422 && json.errors) {
                    // Marca las líneas que el servidor rechazó; las demás siguen en el borrador.
                    borrador.erroresServidor = {};
                    const generales = [];

                    Object.entries(json.errors).forEach(([campo, mensajes]) => {
                        const coincide = campo.match(/^lineas\.(\d+)/);

                        if (coincide) {
                            borrador.erroresServidor[Number(coincide[1])] = mensajes;
                        } else {
                            generales.push(mensajes[0]);
                        }
                    });

                    // La ronda no se guardó: con otra clave se podrá reenviar ya corregida.
                    borrador.clave = null;
                    guardar();
                    mostrarError(generales.join(' ') || 'Hay productos que ya no se pueden pedir. Están marcados abajo.');
                    validar();

                    return;
                }

                if (xhr.status === 409) {
                    mostrarError(json.message || 'La cuenta cambió. Vuelve a abrirla.');
                } else if (xhr.status === 403) {
                    mostrarError(json.message || 'No puedes enviar esta ronda.');
                } else if (xhr.status === 419) {
                    mostrarError('Tu sesión expiró. Recarga la página: la ronda sigue guardada.');
                } else {
                    // Puede que haya llegado: el reintento usa la misma clave y no crea otra ronda.
                    mostrarError('No pudimos confirmar el envío. Toca otra vez «Enviar a cocina»: no se repetirá la ronda.');
                }

                pintar();
            });
    }

    initBusqueda();
    initChips();
    initHojas();
    initDetalle({
        hoja: document.getElementById('hoja-producto'),
        productos: datos.productos,
        cantidadMaxima: datos.cantidadMaxima,
        alAgregar(linea, producto) {
            const existente = borrador.lineas.find((l) => claveLinea(l) === claveLinea(linea));

            if (existente) {
                existente.cantidad = Math.min(datos.cantidadMaxima, existente.cantidad + linea.cantidad);
            } else if (borrador.lineas.length >= datos.lineasMaximas) {
                return `Una ronda admite hasta ${datos.lineasMaximas} productos distintos.`;
            } else {
                borrador.lineas.push(linea);
            }

            cambio();
            anunciar(`${producto.nombre} se agregó a la ronda.`);

            return null;
        },
    });

    $('[data-borrador-abrir]').on('click', () => {
        abrirHoja(hoja);
        reprogramar();
    });

    const cambiar = (atributo, accion) => {
        $hoja.on('click', `[${atributo}]`, function () {
            accion(Number($(this).attr(atributo)));
            cambio();
        });
    };

    cambiar('data-linea-menos', (i) => {
        borrador.lineas[i].cantidad = Math.max(1, borrador.lineas[i].cantidad - 1);
    });
    cambiar('data-linea-mas', (i) => {
        borrador.lineas[i].cantidad = Math.min(datos.cantidadMaxima, borrador.lineas[i].cantidad + 1);
    });
    cambiar('data-linea-quitar', (i) => {
        borrador.lineas.splice(i, 1);
    });

    $hoja.on('click', '[data-borrador-enviar]', enviar);

    pintar();

    if (borrador.lineas.length) {
        validar();
    }
}

$(initTomaPedido);
