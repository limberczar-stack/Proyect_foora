/**
 * FOORA: panel de pedidos (jQuery).
 *
 * - La lista y el detalle llegan como HTML ya escapado desde el servidor; aquí solo se insertan.
 * - Detalle en el panel derecho (escritorio) o en una hoja (celular y tablet).
 * - Acciones por AJAX con el botón bloqueado mientras se envían; si el pedido ya cambió (409),
 *   se avisa y se recarga.
 * - Novedades cada 10 segundos, en pausa con la pestaña oculta: contadores, badge del menú,
 *   y sonido más aviso cuando llega un pedido con id mayor al último visto.
 */
import $ from 'jquery';

const INTERVALO_MS = 10000;
const TIEMPO_MAXIMO_MS = 10000;
const AVISO_VISIBLE_MS = 8000;

const esPantallaChica = window.matchMedia('(max-width: 1023px)');

function initPedidos() {
    const $raiz = $('[data-pedidos]');

    if (!$raiz.length) {
        return;
    }

    const urlNovedades = $raiz.data('url-novedades');
    const tipo = $raiz.data('tipo') || '';
    const hoja = document.getElementById('hoja-pedido');
    const $anuncio = $('[data-anuncio-pedidos]');

    let ultimoId = Number($raiz.data('ultimo-id')) || 0;
    let urlDetalle = null;
    let accionEnCurso = false;
    let temporizador = null;
    let consulta = null;
    let ultimoConteo = null;
    let audio = null;
    let ocultarAviso = null;

    const anunciar = (mensaje) => $anuncio.text(mensaje);

    /* ------------------------------ Detalle ------------------------------ */

    function destinoDetalle() {
        return esPantallaChica.matches ? $(hoja).find('[data-detalle-hoja]') : $('[data-detalle-panel]');
    }

    function marcarFila(id) {
        $('[data-fila-pedido]').attr('aria-selected', 'false');
        $(`[data-fila-pedido="${Number(id)}"]`).attr('aria-selected', 'true');
    }

    function cargarDetalle(url) {
        urlDetalle = url;
        const $destino = destinoDetalle();

        $destino.attr('aria-busy', 'true').empty().append(
            $('<div class="flex items-center gap-3 px-5 py-10 text-sm text-muted"></div>').append(
                $('<span class="size-4 animate-spin-fast rounded-full border-2 border-brand-200 border-t-brand-700"></span>'),
                $('<span></span>').text('Cargando pedido…'),
            ),
        );

        if (esPantallaChica.matches && !hoja.open) {
            hoja.showModal();
        }

        return $.ajax({ url, dataType: 'html', timeout: TIEMPO_MAXIMO_MS })
            .done((html) => {
                $destino.html(html);
                marcarFila($destino.find('[data-detalle]').data('detalle'));
            })
            .fail((xhr) => {
                const mensaje = xhr.status === 403 || xhr.status === 404 ? 'Este pedido ya no está disponible para ti.' : 'No pudimos cargar el pedido.';
                const $error = $('<div class="flex flex-col items-start gap-3 px-5 py-8"></div>').append($('<p class="text-sm text-danger-ink"></p>').text(mensaje));

                if (xhr.status !== 403 && xhr.status !== 404) {
                    $error.append($('<button type="button" class="press inline-flex h-10 items-center rounded-control border border-control bg-surface px-3 text-sm font-medium" data-reintentar-detalle>Reintentar</button>'));
                }

                $destino.empty().append($error);
            })
            .always(() => $destino.removeAttr('aria-busy'));
    }

    $(document).on('click', '[data-abrir-pedido]', function (evento) {
        evento.preventDefault();
        cargarDetalle($(this).data('abrir-pedido'));
        history.replaceState(null, '', this.href);
    });

    // Toda la fila abre el pedido, no solo el número.
    $(document).on('click', '[data-fila-pedido]', function (evento) {
        if (!$(evento.target).closest('a, button').length) {
            $(this).find('[data-abrir-pedido]').trigger('click');
        }
    });

    $(document).on('click', '[data-reintentar-detalle]', () => urlDetalle && cargarDetalle(urlDetalle));

    // Muestra un formulario oculto (cancelar) y puede dejar elegido un motivo.
    $(document).on('click', '[data-mostrar]', function () {
        const $formulario = $(`#${$(this).data('mostrar')}`);

        $formulario.prop('hidden', false);

        if ($(this).data('motivo')) {
            $formulario.find('[name="motivo"]').val($(this).data('motivo'));
        }

        $formulario.find('select, input:not([type="hidden"])').first().trigger('focus');
    });

    /* ------------------------------ Acciones ----------------------------- */

    function errorDeAccion($formulario, mensaje) {
        $formulario.closest('[data-detalle]').find('[data-accion-error]').text(mensaje).removeClass('hidden');
    }

    $(document).on('submit', '[data-accion-form]', function (evento) {
        evento.preventDefault();

        if (accionEnCurso) {
            return;
        }

        const $formulario = $(this);
        const $boton = $formulario.find('[data-accion-enviar]');

        accionEnCurso = true;
        $('[data-accion-enviar]').prop('disabled', true);
        $boton.attr('aria-busy', 'true').data('texto', $boton.text()).text('Guardando…');
        $formulario.closest('[data-detalle]').find('[data-accion-error]').addClass('hidden');

        $.ajax({ url: $formulario.attr('action'), method: 'POST', data: $formulario.serialize(), dataType: 'json', timeout: TIEMPO_MAXIMO_MS })
            .done((respuesta) => {
                anunciar(respuesta.mensaje);
                mostrarAviso(respuesta.mensaje);
                refrescarTodo();
            })
            .fail((xhr) => {
                const mensaje = xhr.responseJSON?.message;

                if (xhr.status === 409) {
                    mostrarAviso(mensaje);
                    anunciar(mensaje);
                    refrescarTodo();
                    return;
                }

                if (xhr.status === 422) {
                    const errores = Object.values(xhr.responseJSON?.errors ?? {}).flat();
                    errorDeAccion($formulario, errores[0] || mensaje);
                } else if (xhr.status === 403) {
                    errorDeAccion($formulario, 'No tienes permiso para hacer este cambio.');
                } else if (xhr.status === 419) {
                    errorDeAccion($formulario, 'Tu sesión expiró. Recarga la página.');
                } else {
                    errorDeAccion($formulario, 'No se pudo guardar. Revisa tu conexión e inténtalo otra vez.');
                }

                $('[data-accion-enviar]').prop('disabled', false);
                $boton.removeAttr('aria-busy').text($boton.data('texto'));
            })
            .always(() => {
                accionEnCurso = false;
            });
    });

    /* ------------------------------- Lista ------------------------------- */

    function recargarLista() {
        return $.ajax({ url: window.location.href, dataType: 'html', timeout: TIEMPO_MAXIMO_MS }).done((html) => {
            $('[data-lista]').html(html);

            if (urlDetalle) {
                marcarFila(destinoDetalle().find('[data-detalle]').data('detalle'));
            }
        });
    }

    // Tras una acción: lista, detalle y, sin esperar al siguiente ciclo, contadores y badge.
    function refrescarTodo() {
        recargarLista();

        if (urlDetalle) {
            cargarDetalle(urlDetalle);
        }

        consultar();
    }

    /* ----------------------------- Novedades ----------------------------- */

    function pintarContadores(datos) {
        Object.entries(datos.contadores).forEach(([estado, total]) => {
            $(`[data-contador="${estado}"]`).text(total);
        });

        const $badge = $('[data-nav-badge="pedidos"]');
        $badge.find('[data-nav-badge-numero]').text(datos.badge);
        $badge.prop('hidden', datos.badge === 0);
    }

    function mostrarAviso(texto) {
        const $aviso = $('[data-aviso-nuevo]');

        $aviso.find('[data-aviso-nuevo-texto]').text(texto);
        $aviso.prop('hidden', false);
        clearTimeout(ocultarAviso);
        ocultarAviso = setTimeout(() => $aviso.prop('hidden', true), AVISO_VISIBLE_MS);
    }

    $(document).on('click', '[data-aviso-cerrar]', () => $('[data-aviso-nuevo]').prop('hidden', true));

    function avisarNuevos(nuevos) {
        const texto =
            nuevos.length === 1
                ? `Pedido nuevo #${nuevos[0].numero} de ${nuevos[0].cliente} · ${nuevos[0].tipo}`
                : `${nuevos.length} pedidos nuevos`;

        mostrarAviso(texto);
        anunciar(texto);
        sonar();
    }

    function consultar() {
        if (consulta || document.hidden) {
            return;
        }

        consulta = $.ajax({ url: urlNovedades, data: { desde: ultimoId, tipo }, dataType: 'json', timeout: TIEMPO_MAXIMO_MS, cache: false });

        consulta
            .done((datos) => {
                $('[data-error-vivo]').addClass('hidden').removeClass('flex');
                pintarContadores(datos);

                const conteo = JSON.stringify(datos.contadores);
                const huboCambios = datos.nuevos.length > 0 || (ultimoConteo !== null && conteo !== ultimoConteo);

                ultimoConteo = conteo;
                ultimoId = Math.max(ultimoId, datos.ultimo_id);

                if (datos.nuevos.length) {
                    avisarNuevos(datos.nuevos);
                }

                if (huboCambios && !accionEnCurso) {
                    recargarLista();
                }
            })
            .fail((xhr, estado) => {
                if (estado !== 'abort') {
                    $('[data-error-vivo]').removeClass('hidden').addClass('flex');
                }
            })
            .always(() => {
                consulta = null;
                programar();
            });
    }

    function programar() {
        clearTimeout(temporizador);

        if (!document.hidden) {
            temporizador = setTimeout(consultar, INTERVALO_MS);
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(temporizador);
        } else {
            consultar();
        }
    });

    $(document).on('click', '[data-reintentar-vivo]', consultar);

    /* ------------------------------- Sonido ------------------------------ */

    // El navegador solo deja sonar después de un clic: por eso existe "Activar sonido".
    function sonar() {
        if (!audio) {
            return;
        }

        const ahora = audio.currentTime;

        [880, 1175].forEach((frecuencia, indice) => {
            const oscilador = audio.createOscillator();
            const volumen = audio.createGain();
            const inicio = ahora + indice * 0.18;

            oscilador.frequency.value = frecuencia;
            volumen.gain.setValueAtTime(0.0001, inicio);
            volumen.gain.exponentialRampToValueAtTime(0.25, inicio + 0.02);
            volumen.gain.exponentialRampToValueAtTime(0.0001, inicio + 0.16);
            oscilador.connect(volumen).connect(audio.destination);
            oscilador.start(inicio);
            oscilador.stop(inicio + 0.17);
        });
    }

    $(document).on('click', '[data-activar-sonido]', function () {
        const Contexto = window.AudioContext || window.webkitAudioContext;

        if (!Contexto) {
            return;
        }

        audio = new Contexto();
        audio.resume();
        sonar();
        $(this).prop('hidden', true);
        $('[data-sonido-activo]').prop('hidden', false);
    });

    /* ------------------------------- Inicio ------------------------------ */

    const $inicial = $('[data-detalle-panel] [data-detalle]');

    if ($inicial.length) {
        urlDetalle = `${window.location.pathname.replace(/\/$/, '')}/${$inicial.data('detalle')}`;
        marcarFila($inicial.data('detalle'));

        if (esPantallaChica.matches) {
            cargarDetalle(urlDetalle);
        }
    }

    programar();
}

$(initPedidos);
