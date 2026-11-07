/**
 * FOORA: Configuración del local, usuarios y Mi cuenta (jQuery).
 *
 * Formularios form[data-ajax-form]: se envían por AJAX (con archivos), y sin JavaScript siguen
 * funcionando como formularios normales.
 * - Mientras guarda: botón ocupado y deshabilitado, campos de solo lectura y texto "Guardando…".
 * - 422: error debajo de cada campo ([data-error-for="clave.con.puntos"]) y foco en el primero;
 *   los que no tienen campo van al resumen [data-form-resumen].
 * - Sin red, tiempo agotado o error del servidor: aviso con "Reintentar", que reenvía lo mismo.
 * - Éxito: aviso breve y evento foora:guardado con la respuesta, para lo propio de cada pantalla.
 *
 * Todo texto que llega del servidor se inserta con .text(): nunca como HTML.
 */
import $ from 'jquery';

const TIEMPO_MAXIMO_MS = 20000;
const AVISO_GUARDADO_MS = 4000;

/* -------------------------------------------------------------------------- */
/* Formularios AJAX                                                            */
/* -------------------------------------------------------------------------- */

function claveANombre(clave) {
    const [primera, ...resto] = clave.split('.');

    return primera + resto.map((parte) => `[${parte}]`).join('');
}

function idDeError(clave) {
    return `error-${clave.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
}

function limpiarErrores($form) {
    $form.find('[data-error-for]').each(function () {
        $(this).text('').prop('hidden', true);
    });

    $form.find('[aria-invalid="true"]').each(function () {
        const $campo = $(this);
        const propia = $campo.data('error-describedby');

        $campo.removeAttr('aria-invalid');

        if (propia) {
            const resto = ($campo.attr('aria-describedby') || '').split(' ').filter((id) => id && id !== propia);
            resto.length ? $campo.attr('aria-describedby', resto.join(' ')) : $campo.removeAttr('aria-describedby');
            $campo.removeData('error-describedby');
        }
    });

    $form.find('[data-form-resumen]').prop('hidden', true).find('ul').empty();
}

function mostrarErrores($form, errores) {
    const sueltos = [];
    let primero = null;

    Object.entries(errores).forEach(([clave, mensajes]) => {
        const mensaje = Array.isArray(mensajes) ? mensajes[0] : String(mensajes);
        const $destino = $form.find(`[data-error-for="${CSS.escape(clave)}"]`);
        const $campo = $form.find(`[name="${CSS.escape(claveANombre(clave))}"]`).not('[type="hidden"]').first();

        if (!$destino.length) {
            sueltos.push(mensaje);

            return;
        }

        const id = $destino.attr('id') || idDeError(clave);
        $destino.attr('id', id).text(mensaje).prop('hidden', false);

        if ($campo.length) {
            const actuales = ($campo.attr('aria-describedby') || '').split(' ').filter(Boolean);

            if (!actuales.includes(id)) {
                $campo.attr('aria-describedby', [...actuales, id].join(' ')).data('error-describedby', id);
            }

            $campo.attr('aria-invalid', 'true');
            primero ??= $campo[0];
        } else {
            primero ??= $destino[0];
        }
    });

    const $resumen = $form.find('[data-form-resumen]');

    if (sueltos.length && $resumen.length) {
        const $lista = $resumen.find('ul').empty();
        sueltos.forEach((mensaje) => $lista.append($('<li></li>').text(mensaje)));
        $resumen.prop('hidden', false);
        primero ??= $resumen[0];
    }

    if (primero) {
        if (primero.matches('input, select, textarea, button')) {
            primero.focus();
        } else {
            primero.setAttribute('tabindex', '-1');
            primero.focus();
        }
    }
}

function pintarEstado($form, tono, mensaje, { reintentar = false } = {}) {
    const $estado = $form.find('[data-form-estado]');

    if (!$estado.length) {
        return;
    }

    clearTimeout($estado.data('temporizador'));

    const tonos = {
        ok: 'text-success-ink',
        error: 'text-danger-ink',
        ocupado: 'text-muted',
    };

    $estado
        .empty()
        .attr('class', `guardado-estado flex min-w-0 items-center gap-2 text-sm ${tonos[tono] || 'text-muted'}`)
        .prop('hidden', !mensaje);

    if (!mensaje) {
        return;
    }

    $estado.append($('<span class="min-w-0"></span>').text(mensaje));

    if (reintentar) {
        $estado.append(
            $('<button type="button" class="press inline-flex h-9 shrink-0 items-center rounded-control border border-control bg-surface px-3 text-[0.8125rem] font-medium text-ink hover:bg-raised" data-form-reintentar></button>').text('Reintentar'),
        );
    }

    if (tono === 'ok') {
        $estado.data(
            'temporizador',
            setTimeout(() => $estado.prop('hidden', true), AVISO_GUARDADO_MS),
        );
    }
}

function ocupar($form, ocupado) {
    const $boton = $form.find('[data-guardar]');

    $form.attr('aria-busy', String(ocupado)).data('ocupado', ocupado);
    $form.find('button[type="submit"], [data-form-reintentar]').prop('disabled', ocupado);
    $boton.toggleClass('cursor-progress', ocupado);
    $boton.find('[data-guardar-spinner]').prop('hidden', !ocupado);

    const $texto = $boton.find('[data-guardar-texto]');

    if (ocupado) {
        $texto.data('original', $texto.data('original') || $texto.text()).text($boton.data('texto-ocupado') || 'Guardando…');
    } else if ($texto.data('original')) {
        $texto.text($texto.data('original'));
    }

    // fieldset[disabled] dejaría fuera los campos del FormData: se usa solo lectura visual.
    $form.find('[data-form-campos]').toggleClass('pointer-events-none opacity-70', ocupado);
}

function enviar($form) {
    if ($form.data('ocupado')) {
        return;
    }

    const datos = new FormData($form[0]);

    limpiarErrores($form);
    ocupar($form, true);
    pintarEstado($form, 'ocupado', $form.data('texto-ocupado') || 'Guardando…');

    $.ajax({
        url: $form.attr('action'),
        method: 'POST',
        data: datos,
        processData: false,
        contentType: false,
        dataType: 'json',
        timeout: TIEMPO_MAXIMO_MS,
        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
    })
        .done((respuesta) => {
            pintarEstado($form, 'ok', respuesta.mensaje || 'Cambios guardados.');
            $('[data-anuncio-panel]').text(respuesta.mensaje || 'Cambios guardados.');
            $form.trigger('foora:guardado', [respuesta]);

            if (respuesta.redirect) {
                window.location.assign(respuesta.redirect);
            }
        })
        .fail((xhr) => {
            const json = xhr.responseJSON || {};

            if (xhr.status === 422 && json.errors) {
                mostrarErrores($form, json.errors);
                pintarEstado($form, 'error', 'Revisa los campos marcados.');

                return;
            }

            if (xhr.status === 403 && json.redirect) {
                window.location.assign(json.redirect);

                return;
            }

            let mensaje;
            let reintentar = false;

            if (xhr.status === 0 || xhr.statusText === 'timeout') {
                mensaje = 'Sin conexión: no se guardó nada.';
                reintentar = true;
            } else if (xhr.status === 419) {
                mensaje = 'Tu sesión expiró. Recarga la página para seguir.';
            } else if (xhr.status === 429 || xhr.status === 403 || xhr.status === 404) {
                mensaje = json.message || 'No se pudo hacer este cambio.';
            } else {
                mensaje = 'Algo falló en el servidor y no se guardó.';
                reintentar = true;
            }

            pintarEstado($form, 'error', mensaje, { reintentar });
            $form.trigger('foora:fallo', [xhr]);
        })
        .always(() => ocupar($form, false));
}

function initFormularios() {
    $(document).on('submit', 'form[data-ajax-form]', function (evento) {
        evento.preventDefault();
        enviar($(this));
    });

    $(document).on('click', '[data-form-reintentar]', function () {
        enviar($(this).closest('form'));
    });

    // Al corregir un campo, su error desaparece.
    $(document).on('input change', 'form[data-ajax-form] [aria-invalid="true"]', function () {
        const $campo = $(this);
        const id = $campo.data('error-describedby');

        $campo.removeAttr('aria-invalid');

        if (id) {
            $(`#${CSS.escape(id)}`).prop('hidden', true);
        }
    });
}

/* -------------------------------------------------------------------------- */
/* General: logo y enlace de la vitrina                                        */
/* -------------------------------------------------------------------------- */

const LOGO_TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
const LOGO_MAX_BYTES = 2 * 1024 * 1024;

function initGeneral() {
    const $raiz = $('[data-config-general]');

    if (!$raiz.length) {
        return;
    }

    const $archivo = $raiz.find('[data-logo-archivo]');
    const $quitar = $raiz.find('[name="quitar_logo"]');
    const $imagen = $raiz.find('[data-logo-imagen]');
    const $iniciales = $raiz.find('[data-logo-iniciales]');
    const $botonQuitar = $raiz.find('[data-logo-quitar]');
    const $error = $raiz.find('[data-error-for="logo"]');
    let urlTemporal = null;

    function mostrarLogo(url) {
        if (url) {
            $imagen.attr('src', url).prop('hidden', false);
            $iniciales.prop('hidden', true);
            $botonQuitar.prop('hidden', false);
        } else {
            $imagen.removeAttr('src').prop('hidden', true);
            $iniciales.prop('hidden', false);
            $botonQuitar.prop('hidden', true);
        }
    }

    $archivo.on('change', function () {
        const archivo = this.files?.[0];

        $error.text('').prop('hidden', true);

        if (!archivo) {
            return;
        }

        if (!LOGO_TIPOS.includes(archivo.type)) {
            $error.text('El logo debe ser una imagen JPG, PNG o WEBP.').prop('hidden', false);
            this.value = '';

            return;
        }

        if (archivo.size > LOGO_MAX_BYTES) {
            $error.text('El logo debe pesar como máximo 2 MB.').prop('hidden', false);
            this.value = '';

            return;
        }

        if (urlTemporal) {
            URL.revokeObjectURL(urlTemporal);
        }

        urlTemporal = URL.createObjectURL(archivo);
        $quitar.val('0');
        mostrarLogo(urlTemporal);
        $raiz.find('[data-logo-pendiente]').prop('hidden', false);
    });

    $botonQuitar.on('click', () => {
        $archivo.val('');
        $quitar.val('1');
        mostrarLogo(null);
        $raiz.find('[data-logo-pendiente]').prop('hidden', false);
        $raiz.find('[data-logo-elegir]').trigger('focus');
    });

    $raiz.find('[data-logo-elegir]').on('click', () => $archivo.trigger('click'));

    $raiz.find('form').on('foora:guardado', (evento, respuesta) => {
        $archivo.val('');
        $quitar.val('0');
        mostrarLogo(respuesta.logo || null);
        $raiz.find('[data-logo-pendiente]').prop('hidden', true);

        if (respuesta.nombre) {
            $iniciales.text(respuesta.nombre.trim().split(/\s+/).slice(0, 2).map((parte) => parte[0]?.toUpperCase() || '').join(''));
        }
    });
}

function initCopiar() {
    $(document).on('click', '[data-copiar]', async function () {
        const $boton = $(this);
        const texto = $boton.data('copiar');
        const $etiqueta = $boton.find('[data-copiar-texto]');
        const original = $etiqueta.data('original') || $etiqueta.text();

        $etiqueta.data('original', original);

        let copiado = false;

        try {
            await navigator.clipboard.writeText(texto);
            copiado = true;
        } catch {
            // Sin permiso para el portapapeles: se selecciona el texto para copiarlo a mano.
            const destino = document.getElementById($boton.data('copiar-respaldo'));

            if (destino) {
                const rango = document.createRange();
                rango.selectNodeContents(destino);
                window.getSelection()?.removeAllRanges();
                window.getSelection()?.addRange(rango);
            }
        }

        $etiqueta.text(copiado ? 'Copiado' : 'Selecciónalo y cópialo');
        $('[data-anuncio-panel]').text(copiado ? 'Copiado al portapapeles.' : 'No se pudo copiar solo: el texto quedó seleccionado.');
        clearTimeout($boton.data('temporizador'));
        $boton.data('temporizador', setTimeout(() => $etiqueta.text(original), 2000));
    });
}

/* -------------------------------------------------------------------------- */
/* Horario                                                                     */
/* -------------------------------------------------------------------------- */

function initHorario() {
    const $raiz = $('[data-config-horario]');

    if (!$raiz.length) {
        return;
    }

    function actualizarFila($fila) {
        const cerrado = $fila.find('[data-dia-cerrado]').is(':checked');
        const abre = $fila.find('[data-dia-abre]').val();
        const cierra = $fila.find('[data-dia-cierra]').val();

        $fila.find('[data-dia-abre], [data-dia-cierra]').prop('disabled', cerrado);
        $fila.find('[data-dia-horas]').toggleClass('opacity-50', cerrado);
        $fila.find('[data-dia-estado]').text(cerrado ? 'Cerrado' : 'Abierto');

        // Coherente con HorarioAtencion::cruzaMedianoche(): cierre menor que apertura termina al día siguiente.
        $fila.find('[data-dia-madrugada]').prop('hidden', cerrado || !abre || !cierra || cierra >= abre);
    }

    $raiz.find('[data-dia]').each(function () {
        actualizarFila($(this));
    });

    $raiz.on('change input', '[data-dia-cerrado], [data-dia-abre], [data-dia-cierra]', function () {
        actualizarFila($(this).closest('[data-dia]'));
    });

    $raiz.on('click', '[data-copiar-dia]', function () {
        const $origen = $(this).closest('[data-dia]');
        const cerrado = $origen.find('[data-dia-cerrado]').is(':checked');
        const abre = $origen.find('[data-dia-abre]').val();
        const cierra = $origen.find('[data-dia-cierra]').val();

        $raiz.find('[data-dia]').not($origen).each(function () {
            const $fila = $(this);
            $fila.find('[data-dia-cerrado]').prop('checked', cerrado);
            $fila.find('[data-dia-abre]').val(abre);
            $fila.find('[data-dia-cierra]').val(cierra);
            actualizarFila($fila);
        });

        const dia = $origen.data('dia-nombre');
        $('[data-anuncio-panel]').text(`Se copió el horario del ${dia} a los demás días. Falta guardar.`);
        pintarEstado($raiz.find('form'), 'ocupado', `Se copió el horario del ${dia}. Guarda para aplicarlo.`);
    });
}

/* -------------------------------------------------------------------------- */
/* Pago                                                                        */
/* -------------------------------------------------------------------------- */

function initPago() {
    const $raiz = $('[data-config-pago]');

    if (!$raiz.length) {
        return;
    }

    function actualizar($metodo) {
        const activo = $metodo.find('[data-metodo-activo]').is(':checked');
        $metodo.find('[data-metodo-cuenta]').prop('hidden', !activo);
    }

    $raiz.find('[data-metodo]').each(function () {
        actualizar($(this));
    });

    $raiz.on('change', '[data-metodo-activo]', function () {
        const $metodo = $(this).closest('[data-metodo]');
        actualizar($metodo);

        if ($(this).is(':checked')) {
            $metodo.find('[data-metodo-cuenta] input').filter((_, campo) => !campo.value).first().trigger('focus');
        }
    });
}

/* -------------------------------------------------------------------------- */
/* Usuarios                                                                    */
/* -------------------------------------------------------------------------- */

function initUsuarios() {
    const $raiz = $('[data-config-usuarios]');

    if (!$raiz.length) {
        return;
    }

    const modalUsuario = document.getElementById('modal-usuario');
    const modalClave = document.getElementById('modal-clave');
    const modalConfirmar = document.getElementById('modal-confirmar-usuario');
    const $formUsuario = $(modalUsuario).find('form');
    const urlCrear = $formUsuario.data('url-crear');
    let recargarAlCerrar = false;

    function abrirFormulario(datos = null) {
        limpiarErrores($formUsuario);
        pintarEstado($formUsuario, null, '');
        $formUsuario[0].reset();

        const editando = datos !== null;

        $formUsuario.attr('action', editando ? datos.url : urlCrear);
        $formUsuario.find('[name="_method"]').val(editando ? 'PUT' : 'POST');
        $(modalUsuario).find(`#${modalUsuario.id}-title`).text(editando ? 'Editar usuario' : 'Nuevo usuario');
        $formUsuario.find('[data-guardar-texto]').text(editando ? 'Guardar cambios' : 'Crear usuario').removeData('original');
        $formUsuario.find('[data-solo-nuevo]').prop('hidden', editando).find('input').prop('disabled', editando);
        $formUsuario.find('[data-solo-edicion]').prop('hidden', !editando);

        if (editando) {
            $formUsuario.find('[name="name"]').val(datos.name);
            $formUsuario.find('[name="telefono"]').val(datos.telefono || '');
            $formUsuario.find('[name="rol"]').val(datos.rol);
            $formUsuario.find('[data-email-fijo]').text(datos.email || '');

            // Nadie cambia su propio rol: el campo queda fijo con el motivo a la vista.
            const propio = Boolean(datos.propio);
            $formUsuario.find('[name="rol"]').prop('disabled', propio);
            $formUsuario.find('[data-rol-propio]').prop('hidden', !propio);
            $formUsuario.find('[data-rol-fijo]').prop('disabled', !propio).val(datos.rol);
        } else {
            $formUsuario.find('[name="rol"]').prop('disabled', false);
            $formUsuario.find('[data-rol-propio]').prop('hidden', true);
            $formUsuario.find('[data-rol-fijo]').prop('disabled', true);
        }

        modalUsuario.showModal();
        $formUsuario.find('[name="name"]').trigger('focus');
    }

    /**
     * La contraseña temporal solo vive en este modal mientras está abierto; al cerrarlo se borra del DOM.
     */
    function mostrarClave(respuesta) {
        const $modal = $(modalClave);

        $modal.find('[data-clave-nombre]').text(respuesta.usuario?.name || '');
        $modal.find('[data-clave-email]').text(respuesta.usuario?.email || '');
        $modal.find('[data-clave-valor]').text(respuesta.password_temporal);
        $modal.find('[data-copiar]').data('copiar', respuesta.password_temporal);
        $modal.find('[data-clave-mensaje]').text(respuesta.mensaje || '');
        recargarAlCerrar = true;
        modalClave.showModal();
    }

    $(modalClave).on('close', () => {
        const $modal = $(modalClave);
        $modal.find('[data-clave-valor]').text('');
        $modal.find('[data-copiar]').removeData('copiar');

        if (recargarAlCerrar) {
            window.location.reload();
        }
    });

    $raiz.on('click', '[data-usuario-nuevo]', () => abrirFormulario());

    $raiz.on('click', '[data-usuario-editar]', function () {
        abrirFormulario($(this).data('usuario-editar'));
    });

    $formUsuario.on('foora:guardado', (evento, respuesta) => {
        if (respuesta.password_temporal) {
            modalUsuario.close();
            mostrarClave(respuesta);

            return;
        }

        modalUsuario.close();
        window.location.reload();
    });

    /* Acciones con confirmación: desactivar, activar, restablecer contraseña */

    const $formConfirmar = $(modalConfirmar).find('form');

    $raiz.on('click', '[data-usuario-accion]', function () {
        const accion = $(this).data('usuario-accion');

        limpiarErrores($formConfirmar);
        pintarEstado($formConfirmar, null, '');
        $formConfirmar.attr('action', accion.url);
        $formConfirmar.find('[name="_method"]').val(accion.metodo);
        $formConfirmar.find('[name="activo"]').val(accion.activo ?? '').prop('disabled', accion.activo === undefined);
        $(modalConfirmar).find(`#${modalConfirmar.id}-title`).text(accion.titulo);
        $(modalConfirmar).find('[data-confirmar-texto]').text(accion.texto);
        $formConfirmar.find('[data-guardar-texto]').text(accion.boton).removeData('original');
        $formConfirmar.find('[data-guardar]').data('texto-ocupado', accion.ocupado);
        $(modalConfirmar).data('returnFocus', this);
        modalConfirmar.showModal();
    });

    $formConfirmar.on('foora:guardado', (evento, respuesta) => {
        modalConfirmar.close();

        if (respuesta.password_temporal) {
            mostrarClave(respuesta);
        } else {
            window.location.reload();
        }
    });
}

/* -------------------------------------------------------------------------- */
/* Mi cuenta                                                                   */
/* -------------------------------------------------------------------------- */

function initMiCuenta() {
    $('[data-mi-cuenta] form[data-ajax-form]').on('foora:guardado', function (evento, respuesta) {
        $(this).find('input[type="password"]').val('');

        if (typeof respuesta.otras_sesiones === 'number') {
            $('[data-otras-sesiones]').text('No hay sesiones abiertas en otros dispositivos.');
        }
    });

    // Mostrar u ocultar lo escrito en un campo de contraseña.
    $(document).on('click', '[data-ver-clave]', function () {
        const $campo = $(`#${CSS.escape($(this).data('ver-clave'))}`);
        const visible = $campo.attr('type') === 'text';

        $campo.attr('type', visible ? 'password' : 'text');
        $(this).attr('aria-pressed', String(!visible)).find('[data-ver-clave-texto]').text(visible ? 'Mostrar' : 'Ocultar');
    });
}

$(() => {
    initFormularios();
    initCopiar();
    initGeneral();
    initHorario();
    initPago();
    initUsuarios();
    initMiCuenta();
});
