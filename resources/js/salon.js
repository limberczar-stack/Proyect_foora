import $ from 'jquery';

/**
 * Plano de mesas para atender (panel/mesas) y detalle de una cuenta.
 *
 * - Actualización en vivo: consulta el plano cada 10 s y cambia solo las mesas que cambiaron.
 *   Se pausa con la pestaña oculta y consulta al volver, igual que el panel de pedidos.
 * - Mesa disponible: abre el formulario de cuenta nueva, con la lista de mesas que se pueden unir.
 * - Mesa ocupada: abre el detalle de su cuenta en la hoja inferior (llega por AJAX).
 * - Cada acción se envía por AJAX con la version que se veía. Si otra persona cambió la cuenta
 *   (409), se avisa y se vuelve a cargar lo que cambió en vez de pisarlo.
 */

const INTERVALO_MS = 10000;
const TIEMPO_MAXIMO_MS = 8000;
const AVISO_VISIBLE_MS = 5000;

const token = () => $('meta[name="csrf-token"]').attr('content');

/* -------------------------------------------------------------------------- */
/* Envío de formularios de cuenta                                              */
/* -------------------------------------------------------------------------- */

function limpiarErrores($form) {
    $form.find('[aria-invalid]').removeAttr('aria-invalid');
    $form.find('[data-error-for]').text('').attr('hidden', true).addClass('hidden');
    $form.closest('dialog, [data-cuenta-pagina]').find('[data-cuenta-error]').addClass('hidden').text('');
}

function mostrarError($form, mensaje) {
    const $destino = $form.closest('dialog, [data-cuenta-pagina]').find('[data-cuenta-error]').first();

    $destino.text(mensaje).removeClass('hidden');
    $destino[0]?.scrollIntoView({ block: 'nearest' });
}

function mostrarErroresDeCampos($form, errores) {
    const sueltos = [];

    Object.entries(errores).forEach(([clave, mensajes]) => {
        const base = clave.split('.')[0];
        const $parrafo = $form.find(`[data-error-for="${clave}"], [data-error-for="${base}"]`).first();

        if (!$parrafo.length) {
            sueltos.push(mensajes[0]);

            return;
        }

        $parrafo.text(mensajes[0]).attr('hidden', false).removeClass('hidden');
        $form.find(`[name="${base}"]`).attr('aria-invalid', 'true');
    });

    if (sueltos.length) {
        mostrarError($form, sueltos.join(' '));
    }
}

function ocupar($form, ocupado) {
    const $boton = $(`[data-cuenta-enviar][form="${$form.attr('id')}"]`).add($form.find('[data-cuenta-enviar]'));

    $form.data('ocupado', ocupado);
    $boton.prop('disabled', ocupado).attr('aria-busy', ocupado ? 'true' : null);
    $boton.text(ocupado ? $boton.data('texto-ocupado') : $boton.data('texto'));
}

/**
 * Envía un formulario de cuenta. alTerminar(mensaje) corre cuando el servidor lo aceptó;
 * alConflicto(mensaje) cuando la cuenta o la mesa ya habían cambiado (409).
 */
function enviar($form, { alTerminar, alConflicto }) {
    if ($form.data('ocupado')) {
        return;
    }

    limpiarErrores($form);
    ocupar($form, true);

    $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        data: $form.serialize(),
        dataType: 'json',
        timeout: TIEMPO_MAXIMO_MS,
        headers: { 'X-CSRF-TOKEN': token() },
    })
        .done((respuesta) => alTerminar(respuesta.mensaje))
        .fail((xhr, estado) => {
            const json = xhr.responseJSON || {};

            if (xhr.status === 409) {
                alConflicto(json.message || 'Esto cambió mientras lo veías.');
            } else if (xhr.status === 422 && json.errors) {
                mostrarErroresDeCampos($form, json.errors);
            } else if (xhr.status === 0 || estado === 'timeout') {
                mostrarError($form, 'Sin conexión: no se guardó nada. Inténtalo otra vez.');
            } else if (xhr.status === 419) {
                mostrarError($form, 'Tu sesión expiró. Recarga la página para seguir.');
            } else if (xhr.status === 403 || xhr.status === 404) {
                mostrarError($form, json.message || 'No puedes hacer este cambio.');
            } else {
                mostrarError($form, 'Algo falló en el servidor y no se guardó. Inténtalo otra vez.');
            }
        })
        .always(() => ocupar($form, false));
}

/* -------------------------------------------------------------------------- */
/* Plano                                                                       */
/* -------------------------------------------------------------------------- */

function initPlano() {
    const $plano = $('[data-plano]');

    if (!$plano.length) {
        return;
    }

    const url = $plano.data('url');
    const maximo = Number($plano.data('mesas-maximo'));
    const $aviso = $plano.find('[data-plano-aviso]');
    const $sinConexion = $plano.find('[data-plano-sin-conexion]');
    const $modalAbrir = $('#modal-abrir-cuenta');
    const $formAbrir = $modalAbrir.find('[data-abrir-form]');
    const $modalCuenta = $('#modal-cuenta');
    const $cuerpo = $modalCuenta.find('[data-cuenta-cuerpo]');
    const cargando = $cuerpo.html();

    let consulta = null;
    let temporizador = null;
    let ocultarAviso = null;
    let cuentaAbierta = null;

    function avisar(mensaje) {
        clearTimeout(ocultarAviso);
        $aviso.text(mensaje).removeClass('hidden').addClass('flex');
        $('[data-anuncio-panel]').text(mensaje);
        ocultarAviso = setTimeout(() => $aviso.addClass('hidden').removeClass('flex'), AVISO_VISIBLE_MS);
    }

    function programar() {
        clearTimeout(temporizador);
        temporizador = setTimeout(consultar, INTERVALO_MS);
    }

    function pintar(datos) {
        const enPagina = $plano.find('[data-mesa]').map((i, el) => String($(el).data('mesa'))).get().sort();
        const enServidor = Object.keys(datos.mesas).sort();

        // El admin agregó, quitó o reordenó mesas: se recarga la página entera.
        if (enPagina.join(',') !== enServidor.join(',')) {
            window.location.reload();

            return;
        }

        Object.entries(datos.mesas).forEach(([id, html]) => {
            const $celda = $plano.find(`[data-mesa="${id}"]`);

            if ($celda.data('html') === html) {
                return;
            }

            const teniaFoco = $celda[0].contains(document.activeElement);

            // La primera vez solo se guarda lo que ya pintó el servidor, salvo que difiera.
            if ($celda.data('html') !== undefined || $.trim($celda.html()) !== $.trim(html)) {
                $celda.html(html);
            }

            $celda.data('html', html);

            if (teniaFoco) {
                $celda.children().first().trigger('focus');
            }
        });

        Object.entries(datos.resumen).forEach(([estado, total]) => {
            $plano.find(`[data-resumen="${estado}"]`).text(total);
        });
    }

    function consultar() {
        if (consulta || document.hidden) {
            return;
        }

        consulta = $.ajax({ url, dataType: 'json', timeout: TIEMPO_MAXIMO_MS, cache: false });

        consulta
            .done((datos) => {
                $sinConexion.addClass('hidden').removeClass('flex');
                pintar(datos);
            })
            .fail((xhr, estado) => {
                if (xhr.status === 401 || xhr.status === 419 || xhr.status === 404) {
                    window.location.reload();
                } else if (estado !== 'abort') {
                    $sinConexion.removeClass('hidden').addClass('flex');
                }
            })
            .always(() => {
                consulta = null;
                programar();
            });
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(temporizador);
        } else {
            consultar();
        }
    });

    programar();

    /* ---- Abrir cuenta ---- */

    function actualizarTope() {
        const $casillas = $formAbrir.find('[data-abrir-lista] input[type="checkbox"]');
        const lleno = $casillas.filter(':checked').length >= maximo - 1;

        $casillas.not(':checked').prop('disabled', lleno);
    }

    $(document).on('click', '[data-mesa-libre]', function () {
        const $mesa = $(this);
        const id = String($mesa.data('mesa-id'));
        const $lista = $formAbrir.find('[data-abrir-lista]').empty();
        const porArea = new Map();

        $plano.find('[data-mesa-libre]').each(function () {
            const $otra = $(this);

            if (String($otra.data('mesa-id')) === id) {
                return;
            }

            const area = $otra.closest('[data-mesa]').data('area');

            porArea.set(area, [...(porArea.get(area) || []), $otra]);
        });

        porArea.forEach((mesas, area) => {
            const $grupo = $('<div>').append($('<p class="mb-1.5 text-xs text-muted">').text(area));
            const $opciones = $('<div class="flex flex-wrap gap-2">').appendTo($grupo);

            mesas.forEach(($otra) => {
                $('<label class="press flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-line px-3 text-sm text-ink has-checked:border-brand-600 has-checked:bg-brand-100 has-disabled:cursor-not-allowed has-disabled:opacity-50">')
                    .append($('<input type="checkbox" name="mesas[]" class="size-4 accent-brand-700">').val($otra.data('mesa-id')))
                    .append($('<span>').text(`${$otra.data('mesa-codigo')} · ${$otra.data('mesa-capacidad')} pers.`))
                    .appendTo($opciones);
            });

            $lista.append($grupo);
        });

        $formAbrir.find('[data-abrir-unir]').attr('hidden', porArea.size === 0);
        $formAbrir.find('[data-abrir-mesa]').val(id);
        $formAbrir.find('[name="comensales"]').val(Math.min(2, Number($mesa.data('mesa-capacidad')) || 2));
        $formAbrir.find('[name="nota"]').val('');
        $modalAbrir.find('#modal-abrir-cuenta-title').text(`Abrir cuenta en la mesa ${$mesa.data('mesa-codigo')}`);
        limpiarErrores($formAbrir);
        actualizarTope();
    });

    $formAbrir.on('change', '[data-abrir-lista] input', actualizarTope);

    $formAbrir.on('submit', function (evento) {
        evento.preventDefault();

        enviar($formAbrir, {
            alTerminar(mensaje) {
                $modalAbrir[0].close();
                avisar(mensaje);
                consultar();
            },
            alConflicto(mensaje) {
                // La mesa ya no está libre: se cierra el formulario y el plano muestra cómo quedó.
                $modalAbrir[0].close();
                avisar(mensaje);
                consultar();
            },
        });
    });

    /* ---- Detalle de una cuenta ---- */

    function cargarCuenta(urlCuenta, mensaje = null) {
        cuentaAbierta = urlCuenta;
        $cuerpo.html(cargando);

        $.ajax({ url: urlCuenta, dataType: 'html', timeout: TIEMPO_MAXIMO_MS, cache: false })
            .done((html) => {
                if (cuentaAbierta !== urlCuenta) {
                    return;
                }

                $cuerpo.html(html);
                $modalCuenta.find('#modal-cuenta-title').text($cuerpo.find('[data-cuenta-detalle]').data('titulo') || 'Cuenta');

                if (mensaje) {
                    $cuerpo.find('[data-cuenta-error]').text(mensaje).removeClass('hidden');
                }
            })
            .fail(() => {
                $cuerpo.html($('<p class="rounded-control bg-danger-bg px-3 py-2.5 text-sm text-danger-ink" role="alert">').text('No se pudo cargar la cuenta. Cierra y vuelve a tocar la mesa.'));
            });
    }

    // Al volver de tomar el pedido (?cuenta=ID) se abre esa cuenta.
    const cuentaPedida = new URL(window.location.href).searchParams.get('cuenta');
    const $enlaceCuenta = cuentaPedida ? $plano.find(`[data-cuenta-abrir][data-cuenta-id="${cuentaPedida}"]`).first() : $();

    if ($enlaceCuenta.length && typeof $modalCuenta[0]?.showModal === 'function') {
        $modalCuenta[0].showModal();
        cargarCuenta($enlaceCuenta.attr('href'));

        const url = new URL(window.location.href);
        url.searchParams.delete('cuenta');
        window.history.replaceState(null, '', url);
    }

    $(document).on('click', '[data-cuenta-abrir]', function (evento) {
        if (evento.ctrlKey || evento.metaKey || evento.shiftKey || typeof $modalCuenta[0]?.showModal !== 'function') {
            return;
        }

        evento.preventDefault();
        $modalCuenta.data('returnFocus', this);
        $modalCuenta.find('#modal-cuenta-title').text('Cuenta');
        $modalCuenta[0].showModal();
        cargarCuenta($(this).attr('href'));
    });

    $modalCuenta.on('submit', 'form[data-cuenta-form]', function (evento) {
        evento.preventDefault();

        const $form = $(this);

        enviar($form, {
            alTerminar(mensaje) {
                avisar(mensaje);
                consultar();

                // Cerrar o anular terminan la cuenta: se cierra la hoja. Lo demás (servir, anular una
                // línea, pedir precuenta, cambiar mesas) deja la cuenta abierta: se recarga su detalle.
                if ($form.is('[data-cierra-hoja]')) {
                    $modalCuenta[0].close();
                } else {
                    cargarCuenta(cuentaAbierta);
                }
            },
            alConflicto(mensaje) {
                // Otra persona cambió la cuenta: se muestra cómo quedó, con el aviso arriba.
                cargarCuenta(cuentaAbierta, mensaje);
                consultar();
            },
        });
    });
}

/* -------------------------------------------------------------------------- */
/* Detalle como página (sin plano detrás)                                      */
/* -------------------------------------------------------------------------- */

function initPaginaCuenta() {
    const $pagina = $('[data-cuenta-pagina]');

    if (!$pagina.length) {
        return;
    }

    $pagina.on('submit', 'form[data-cuenta-form]', function (evento) {
        evento.preventDefault();

        const $form = $(this);

        enviar($form, {
            alTerminar: () => window.location.assign($pagina.data('url-plano')),
            alConflicto: (mensaje) => {
                mostrarError($form, `${mensaje} Recarga la página para ver cómo quedó.`);
            },
        });
    });
}

$(() => {
    initPlano();
    initPaginaCuenta();
});
