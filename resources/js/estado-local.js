/**
 * FOORA: interruptor "Abierto / Cerrado" de la barra lateral.
 *
 * - Abrir: cambia al instante y se envía; si el servidor falla, vuelve a como estaba.
 * - Cerrar: el servidor responde 409 con los pedidos activos; se muestran en un modal y,
 *   al confirmar, se envía de nuevo con confirmar=1.
 * - Mientras se envía, el interruptor queda ocupado (aria-busy) y no acepta otro clic.
 */
import $ from 'jquery';

const TIEMPO_MAXIMO_MS = 10000;

function initEstadoLocal() {
    const $boton = $('[data-estado-local]');

    if (!$boton.length) {
        return;
    }

    const url = $boton.data('url');
    const modal = document.getElementById('modal-cerrar-local');
    const $error = $('[data-estado-local-error]');
    const $anuncio = $('[data-anuncio-panel]');
    let ocupado = false;

    const estaAbierto = () => $boton.attr('aria-checked') === 'true';

    function pintar(abierto) {
        $boton.attr('aria-checked', String(abierto)).attr('data-abierto', abierto ? '1' : '0');
        $boton.find('[data-estado-local-texto]').text(abierto ? 'Abierto' : 'Cerrado');
    }

    function ocupar(si) {
        ocupado = si;
        $boton.attr('aria-busy', String(si));
        $(modal).find('[data-cerrar-local-confirmar]').prop('disabled', si).text(si ? 'Cerrando…' : 'Cerrar el local');
    }

    function mostrarError(mensaje) {
        $error.text(mensaje).prop('hidden', !mensaje);
    }

    function enviar(estado, confirmar = false) {
        const anterior = estaAbierto();

        ocupar(true);
        mostrarError('');

        // Cambio optimista: se ve de inmediato y se revierte si falla.
        if (estado === 'abierto' || confirmar) {
            pintar(estado === 'abierto');
        }

        return $.ajax({
            url,
            method: 'POST',
            data: { _method: 'PATCH', estado, confirmar: confirmar ? 1 : 0 },
            dataType: 'json',
            timeout: TIEMPO_MAXIMO_MS,
        })
            .done((respuesta) => {
                pintar(Boolean(respuesta.abierto));
                $anuncio.text(respuesta.mensaje);

                if (modal?.open) {
                    modal.close();
                }
            })
            .fail((xhr) => {
                if (xhr.status === 409 && xhr.responseJSON?.requiere_confirmacion) {
                    pedirConfirmacion(Number(xhr.responseJSON.pedidos_activos) || 0);

                    return;
                }

                pintar(anterior);

                const mensaje =
                    xhr.status === 0 || xhr.statusText === 'timeout'
                        ? 'Sin conexión: no se cambió el estado. Inténtalo otra vez.'
                        : xhr.status === 419
                          ? 'Tu sesión expiró. Recarga la página.'
                          : xhr.responseJSON?.message || 'No pudimos cambiar el estado del local.';

                mostrarError(mensaje);

                if (modal?.open) {
                    modal.close();
                }
            })
            .always(() => ocupar(false));
    }

    function pedirConfirmacion(activos) {
        const texto =
            activos === 0
                ? 'No tienes pedidos activos en este momento.'
                : activos === 1
                  ? 'Tienes 1 pedido activo. Seguirás pudiendo atenderlo.'
                  : `Tienes ${activos} pedidos activos. Seguirás pudiendo atenderlos.`;

        $(modal).find('[data-cerrar-local-activos]').text(texto);
        $(modal).data('returnFocus', $boton[0]);
        modal.showModal();
    }

    $boton.on('click', () => {
        if (ocupado) {
            return;
        }

        enviar(estaAbierto() ? 'cerrado' : 'abierto');
    });

    $(modal).on('click', '[data-cerrar-local-confirmar]', () => {
        if (!ocupado) {
            enviar('cerrado', true);
        }
    });
}

$(initEstadoLocal);
