/**
 * FOORA: seguimiento público de un pedido. Consulta el estado cada 15 segundos, se pausa con la
 * pestaña oculta y se detiene cuando el pedido terminó. Todo se pinta con .text() o atributos.
 */
import $ from 'jquery';

const INTERVALO_MS = 15000;
const TIEMPO_MAXIMO_MS = 10000;

function olvidarPedidoGuardado({ slug, codigo }) {
    try {
        const clave = `foora.pedido.v1.${slug}`;

        if (window.localStorage.getItem(clave) === codigo) {
            window.localStorage.removeItem(clave);
        }
    } catch {
        // Sin almacenamiento no hay nada que olvidar.
    }
}

function pintar(datos) {
    $('[data-seguimiento-titulo]').text(datos.titulo);
    $('[data-seguimiento-detalle]').text(datos.detalle);
    $('[data-seguimiento-pago]').text(datos.pago);
    $('[data-seguimiento-motivo]').text(datos.motivo ?? '').prop('hidden', !datos.cancelado);
    $('[data-seguimiento-pasos]').prop('hidden', datos.cancelado);

    datos.pasos.forEach((paso) => {
        $('[data-paso]')
            .filter((_, elemento) => elemento.dataset.paso === paso.clave)
            .attr('data-estado', paso.estado);
    });
}

function iniciar(config) {
    let temporizador = null;
    let consulta = null;
    let ultimoEstado = null;

    function detener() {
        clearTimeout(temporizador);
        temporizador = null;
    }

    function programar() {
        detener();

        if (!document.hidden) {
            temporizador = setTimeout(consultar, INTERVALO_MS);
        }
    }

    function consultar() {
        if (consulta) {
            return;
        }

        consulta = $.ajax({ url: config.urlEstado, dataType: 'json', timeout: TIEMPO_MAXIMO_MS, cache: false, headers: { Accept: 'application/json' } });

        consulta
            .done((datos) => {
                $('[data-seguimiento-desconectado]').addClass('hidden');
                pintar(datos);

                if (ultimoEstado !== null && ultimoEstado !== datos.estado) {
                    $('[data-seguimiento-anuncio]').text(`Tu pedido: ${datos.titulo}`);
                }

                ultimoEstado = datos.estado;

                if (datos.final) {
                    olvidarPedidoGuardado(config);
                    config.final = true;
                }
            })
            .fail(() => {
                $('[data-seguimiento-desconectado]').removeClass('hidden');
            })
            .always(() => {
                consulta = null;

                if (!config.final) {
                    programar();
                }
            });
    }

    document.addEventListener('visibilitychange', () => {
        if (config.final) {
            return;
        }

        if (document.hidden) {
            detener();
        } else {
            consultar();
        }
    });

    if (config.final) {
        olvidarPedidoGuardado(config);
    } else {
        programar();
    }
}

$(() => {
    if (window.FOORA_SEGUIMIENTO) {
        iniciar(window.FOORA_SEGUIMIENTO);
    }
});
