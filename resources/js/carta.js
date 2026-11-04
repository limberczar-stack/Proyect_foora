/**
 * FOORA: interacciones del módulo Carta (jQuery).
 *
 * - Filtros del listado: búsqueda con espera de 400 ms y cambio de categoría; muestran estado de carga.
 * - Interruptor de disponibilidad: guarda por AJAX, muestra carga y revierte si falla.
 * - Modales de confirmación para eliminar (producto y categoría).
 * - Modal de categoría: crear y editar con el mismo formulario.
 * - Formulario de producto: precio único o variantes, editor de variantes, vista previa de la foto.
 */
import $ from 'jquery';

const MAX_IMAGEN_BYTES = 2 * 1024 * 1024;
const TIPOS_IMAGEN = ['image/jpeg', 'image/png', 'image/webp'];
const MIN_VARIANTES = 2;

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
/* Formulario de producto                                                      */
/* -------------------------------------------------------------------------- */

function initProductoForm() {
    const $form = $('[data-producto-form]');

    if (!$form.length) {
        return;
    }

    // Precio único o variantes.
    function mostrarTipo() {
        const tipo = $form.find('[data-tipo-radio]:checked').val();

        $form.find('[data-seccion-tipo]').each(function () {
            $(this).prop('hidden', $(this).data('seccion-tipo') !== tipo);
        });
    }

    $form.on('change', '[data-tipo-radio]', mostrarTipo);
    mostrarTipo();

    // Editor de variantes.
    const $lista = $form.find('[data-variantes-lista]');
    const plantilla = $form.find('[data-variante-template]').html();
    let siguiente = $lista.children().length;

    function actualizarControles() {
        const $filas = $lista.children('[data-variante]');

        $filas.each(function (i) {
            $(this).find('[data-variante-subir]').prop('disabled', i === 0);
            $(this).find('[data-variante-bajar]').prop('disabled', i === $filas.length - 1);
            $(this).find('[data-variante-quitar]').prop('disabled', $filas.length <= MIN_VARIANTES);
        });
    }

    $form.on('click', '[data-variante-agregar]', () => {
        const $fila = $(plantilla.replaceAll('__INDICE__', `n${siguiente++}`));
        $lista.append($fila);
        actualizarControles();
        $fila.find('input[type="text"]').first().trigger('focus');
    });

    $form.on('click', '[data-variante-quitar]', function () {
        const $fila = $(this).closest('[data-variante]');
        const $siguienteFoco = $fila.next('[data-variante]').length ? $fila.next() : $fila.prev();

        $fila.remove();
        actualizarControles();
        $siguienteFoco.find('input[type="text"]').first().trigger('focus');
    });

    $form.on('click', '[data-variante-subir]', function () {
        const $fila = $(this).closest('[data-variante]');
        $fila.prev('[data-variante]').before($fila);
        actualizarControles();
        $(this).trigger('focus');
    });

    $form.on('click', '[data-variante-bajar]', function () {
        const $fila = $(this).closest('[data-variante]');
        $fila.next('[data-variante]').after($fila);
        actualizarControles();
        $(this).trigger('focus');
    });

    actualizarControles();

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
});
