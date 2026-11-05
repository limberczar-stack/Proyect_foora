/**
 * FOORA: interacciones del módulo Carta (jQuery).
 *
 * - Filtros del listado: búsqueda con espera de 400 ms y cambio de categoría; muestran estado de carga.
 * - Interruptor de disponibilidad: guarda por AJAX, muestra carga y revierte si falla.
 * - Modales de confirmación para eliminar (producto y categoría).
 * - Modal de categoría: crear y editar con el mismo formulario.
 * - Formulario de producto: precio único, variantes o combo, editor de variantes, grupos asignados, vista previa de la foto.
 * - Formulario de grupo de opciones: tipo, límites y editor de opciones.
 */
import $ from 'jquery';

const MAX_IMAGEN_BYTES = 2 * 1024 * 1024;
const TIPOS_IMAGEN = ['image/jpeg', 'image/png', 'image/webp'];
const MIN_VARIANTES = 2;
const MIN_OPCIONES = 1;

$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
        Accept: 'application/json',
    },
});

/* -------------------------------------------------------------------------- */
/* Filtros del listado                                                         */
/* -------------------------------------------------------------------------- */

function initFiltros() {
    const $form = $('[data-filtros-carta]');

    if (!$form.length) {
        return;
    }

    let espera = null;

    function enviar() {
        $form.find('[data-filtros-spinner]').removeClass('hidden');
        $('[data-listado-carta]').attr('aria-busy', 'true').addClass('opacity-60 transition-opacity duration-150');
        $form.trigger('submit');
    }

    $form.find('input[name="q"]').on('input', () => {
        clearTimeout(espera);
        espera = setTimeout(enviar, 400);
    });

    $form.find('select[name="categoria"]').on('change', enviar);
}

/* -------------------------------------------------------------------------- */
/* Disponibilidad (AJAX)                                                        */
/* -------------------------------------------------------------------------- */

function pintarSwitch($switch, disponible) {
    $switch.attr('aria-checked', String(disponible));
    $switch.find('[data-switch-track]').toggleClass('bg-success-fill', disponible).toggleClass('bg-control', !disponible);
    $switch.find('[data-switch-thumb]').toggleClass('translate-x-5', disponible);
    $switch.find('[data-switch-label]').text(disponible ? 'Disponible' : 'Agotado');
}

function initDisponibilidad() {
    const $live = $('[data-disponibilidad-live]');
    const $error = $('[data-disponibilidad-error]');

    $(document).on('click', '[data-disponibilidad]', function () {
        const $switch = $(this);

        if ($switch.attr('aria-busy') === 'true') {
            return;
        }

        const anterior = $switch.attr('aria-checked') === 'true';
        const nuevo = !anterior;

        // Respuesta inmediata: se pinta el estado nuevo mientras se guarda.
        pintarSwitch($switch, nuevo);
        $switch.attr('aria-busy', 'true').addClass('cursor-progress');
        $switch.find('[data-switch-spinner]').removeClass('hidden');
        $error.addClass('hidden').text('');

        $.ajax({
            url: $switch.data('url'),
            method: 'PATCH',
            data: { disponible: nuevo ? 1 : 0 },
        })
            .done((respuesta) => {
                pintarSwitch($switch, Boolean(respuesta.disponible));
                $live.text(respuesta.mensaje);
            })
            .fail((xhr) => {
                pintarSwitch($switch, anterior);
                const motivo =
                    xhr.status === 403
                        ? 'No tienes permiso para cambiar la disponibilidad.'
                        : xhr.status === 419
                          ? 'Tu sesión expiró. Recarga la página.'
                          : 'No se pudo guardar el cambio. Revisa tu conexión e inténtalo otra vez.';
                $error.removeClass('hidden').text(motivo);
                $live.text(motivo);
            })
            .always(() => {
                $switch.attr('aria-busy', 'false').removeClass('cursor-progress');
                $switch.find('[data-switch-spinner]').addClass('hidden');
            });
    });
}

/* -------------------------------------------------------------------------- */
/* Confirmar eliminación                                                       */
/* -------------------------------------------------------------------------- */

function initEliminar() {
    $(document).on('click', '[data-eliminar-url]', function () {
        const $modal = $(`#${$(this).data('modal-open')}`);

        $modal.find('[data-eliminar-form]').attr('action', $(this).data('eliminar-url'));
        $modal.find('[data-eliminar-texto]').text(`«${$(this).data('eliminar-nombre')}»`);
    });
}

/* -------------------------------------------------------------------------- */
/* Modal de categoría                                                          */
/* -------------------------------------------------------------------------- */

function initCategoriaModal() {
    const $modal = $('#modal-categoria');

    if (!$modal.length) {
        return;
    }

    const $form = $modal.find('[data-categoria-form]');
    const $titulo = $modal.find('#modal-categoria-title');
    const $parent = $form.find('[data-campo="parent_id"]');

    function preparar({ titulo, accion, metodo, nombre = '', descripcion = '', parentId = '', activa = true, conFotos = true, id = null, tieneHijas = false }) {
        $titulo.text(titulo);
        $form.attr('action', accion);
        $form.find('[data-categoria-accion]').val(accion);
        $form.find('[data-categoria-metodo]').val(metodo);
        $form.find('[data-categoria-titulo]').val(titulo);
        $form.find('[data-campo="nombre"]').val(nombre);
        $form.find('[data-campo="descripcion"]').val(descripcion);
        $form.find('#categoria-activa').prop('checked', activa);
        $form.find('#categoria-con-fotos').prop('checked', conFotos);

        // Una categoría no puede ir dentro de sí misma, y si tiene subcategorías debe seguir siendo principal.
        $parent.find('option').prop('disabled', false);
        if (id) {
            $parent.find(`option[value="${id}"]`).prop('disabled', true);
        }
        $parent.val(parentId ? String(parentId) : '').prop('disabled', tieneHijas);

        // Limpia errores de un intento anterior.
        $form.find('[aria-invalid]').removeAttr('aria-invalid').removeClass('border-danger-dot');
        $form.find('[id$="-error"]').remove();
    }

    $(document).on('click', '[data-categoria-nueva]', () => {
        preparar({ titulo: 'Nueva categoría', accion: $form.data('url-crear'), metodo: 'POST' });
    });

    $(document).on('click', '[data-categoria-editar]', function () {
        const $boton = $(this);

        preparar({
            titulo: `Editar «${$boton.data('nombre')}»`,
            accion: $boton.data('url'),
            metodo: 'PUT',
            nombre: $boton.data('nombre'),
            descripcion: $boton.data('descripcion') || '',
            parentId: $boton.data('parent-id') || '',
            activa: Number($boton.data('activa')) === 1,
            conFotos: Number($boton.data('con-fotos')) === 1,
            id: $boton.data('id'),
            tieneHijas: Number($boton.data('tiene-hijas')) === 1,
        });
    });

    // Volvió con errores de validación: se reabre con lo escrito.
    if ($modal.attr('data-reabrir') === 'true') {
        $titulo.text($form.find('[data-categoria-titulo]').val());
        $modal[0].showModal();
    }
}

/* -------------------------------------------------------------------------- */
/* Editor de filas (variantes, opciones y grupos asignados)                    */
/* -------------------------------------------------------------------------- */

/**
 * Agrega, quita y reordena filas. Usa los atributos data-{nombre}, data-{nombre}-lista,
 * -template, -agregar, -subir, -bajar y -quitar. El orden se guarda por posición.
 */
function editorFilas($form, nombre, minimo, alQuitar = () => {}) {
    const fila = `[data-${nombre}]`;
    const $lista = $form.find(`[data-${nombre}-lista]`);
    const plantilla = $form.find(`[data-${nombre}-template]`).html();
    let siguiente = $lista.children().length;

    function actualizar() {
        const $filas = $lista.children(fila);

        $filas.each(function (i) {
            $(this).find(`[data-${nombre}-subir]`).prop('disabled', i === 0);
            $(this).find(`[data-${nombre}-bajar]`).prop('disabled', i === $filas.length - 1);
            $(this).find(`[data-${nombre}-quitar]`).prop('disabled', $filas.length <= minimo);
        });
    }

    $form.on('click', `[data-${nombre}-agregar]`, () => {
        const $nueva = $(plantilla.replaceAll('__INDICE__', `n${siguiente++}`));
        $lista.append($nueva);
        actualizar();
        $nueva.find('input[type="text"]').first().trigger('focus');
    });

    $form.on('click', `[data-${nombre}-quitar]`, function () {
        const $fila = $(this).closest(fila);
        const $siguienteFoco = $fila.next(fila).length ? $fila.next() : $fila.prev();

        alQuitar($fila);
        $fila.remove();
        actualizar();
        $siguienteFoco.find('input[type="text"], button:not(:disabled)').first().trigger('focus');
    });

    $form.on('click', `[data-${nombre}-subir]`, function () {
        const $fila = $(this).closest(fila);
        $fila.prev(fila).before($fila);
        actualizar();
        $(this).trigger('focus');
    });

    $form.on('click', `[data-${nombre}-bajar]`, function () {
        const $fila = $(this).closest(fila);
        $fila.next(fila).after($fila);
        actualizar();
        $(this).trigger('focus');
    });

    actualizar();

    return { actualizar };
}

/* -------------------------------------------------------------------------- */
/* Grupos asignados a un producto                                              */
/* -------------------------------------------------------------------------- */

function initGruposAsignados($form) {
    const $lista = $form.find('[data-asignado-lista]');
    const $select = $form.find('[data-asignado-select]');

    if (!$lista.length || !$select.length) {
        return;
    }

    const plantilla = $form.find('[data-asignado-plantilla]').html();

    function mostrarVacio() {
        $form.find('[data-asignado-vacio]').prop('hidden', $lista.children().length > 0);
    }

    const editor = editorFilas($form, 'asignado', 0, ($fila) => {
        $select.find(`option[value="${$fila.find('input[name="grupos[]"]').val()}"]`).prop('disabled', false);
        setTimeout(mostrarVacio);
    });

    $form.on('click', '[data-asignado-elegir]', () => {
        const $opcion = $select.find('option:selected');

        if (!$opcion.val()) {
            return;
        }

        const $nueva = $(plantilla);
        $nueva.find('input[name="grupos[]"]').val($opcion.val());
        $nueva.find('[data-asignado-nombre]').text($opcion.text());
        $nueva.find('[data-asignado-detalle]').text($opcion.data('detalle'));
        $lista.append($nueva);

        $opcion.prop('disabled', true);
        $select.val('');
        editor.actualizar();
        mostrarVacio();
    });

    mostrarVacio();
}

/* -------------------------------------------------------------------------- */
/* Formulario de grupo de opciones                                             */
/* -------------------------------------------------------------------------- */

function initGrupoForm() {
    const $form = $('[data-grupo-form]');

    if (!$form.length) {
        return;
    }

    const $minimo = $form.find('input[name="min_opciones"]');
    const $obligatorio = $form.find('input[name="obligatorio"][type="checkbox"]');

    function mostrarLimites() {
        $form.find('[data-grupo-limites]').prop('hidden', $form.find('[data-grupo-tipo]:checked').val() !== 'multiple');
    }

    $form.on('change', '[data-grupo-tipo]', mostrarLimites);
    mostrarLimites();

    // Obligatorio y mínimo van juntos: se ajustan para evitar el error antes de enviar.
    $obligatorio.on('change', function () {
        const minimo = Number($minimo.val()) || 0;

        if (this.checked && minimo < 1) {
            $minimo.val(1);
        } else if (!this.checked) {
            $minimo.val(0);
        }
    });

    $minimo.on('input', function () {
        $obligatorio.prop('checked', Number(this.value) > 0);
    });

    editorFilas($form, 'opcion', MIN_OPCIONES);
}

/* -------------------------------------------------------------------------- */
/* Formulario de producto                                                      */
/* -------------------------------------------------------------------------- */

function initProductoForm() {
    const $form = $('[data-producto-form]');

    if (!$form.length) {
        return;
    }

    // Precio único, variantes o combo.
    function mostrarTipo() {
        const tipo = $form.find('[data-tipo-radio]:checked').val();

        $form.find('[data-seccion-tipo]').each(function () {
            $(this).prop('hidden', !String($(this).data('seccion-tipo')).split(' ').includes(tipo));
        });
    }

    $form.on('change', '[data-tipo-radio]', mostrarTipo);
    mostrarTipo();

    editorFilas($form, 'variante', MIN_VARIANTES);
    initGruposAsignados($form);

    // Nota de "foto opcional" según la categoría.
    const $categoria = $form.find('[data-categoria-select]');

    function notaConFotos() {
        const sinFotos = String($categoria.find('option:selected').data('con-fotos')) === '0';
        $form.find('[data-con-fotos-nota]').prop('hidden', !sinFotos);
    }

    $categoria.on('change', notaConFotos);
    notaConFotos();

    // Vista previa y validación de la foto en el navegador (el servidor valida igual).
    const $input = $form.find('[data-imagen-input]');
    const $preview = $form.find('[data-imagen-preview]');
    const $vacia = $form.find('[data-imagen-vacia]');
    const $errorImagen = $form.find('[data-imagen-error]');
    const srcOriginal = $preview.attr('src') || '';

    function mostrarPreview(src) {
        $preview.attr('src', src || '').toggleClass('hidden', !src);
        $vacia.toggleClass('hidden', Boolean(src));
    }

    $input.on('change', function () {
        const archivo = this.files && this.files[0];
        $errorImagen.addClass('hidden').text('');

        if (!archivo) {
            mostrarPreview(srcOriginal);
            return;
        }

        if (!TIPOS_IMAGEN.includes(archivo.type) || archivo.size > MAX_IMAGEN_BYTES) {
            $errorImagen
                .removeClass('hidden')
                .text(archivo.size > MAX_IMAGEN_BYTES ? 'La imagen pesa más de 2 MB.' : 'La imagen debe ser JPG, PNG o WEBP.');
            this.value = '';
            mostrarPreview(srcOriginal);
            return;
        }

        $form.find('[data-quitar-imagen]').prop('checked', false);
        mostrarPreview(URL.createObjectURL(archivo));
    });

    $form.on('change', '[data-quitar-imagen]', function () {
        mostrarPreview(this.checked ? '' : srcOriginal);
    });
}

$(() => {
    initFiltros();
    initDisponibilidad();
    initEliminar();
    initCategoriaModal();
    initProductoForm();
    initGrupoForm();
});
