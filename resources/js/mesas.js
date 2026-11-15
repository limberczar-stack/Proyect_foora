import $ from 'jquery';

/**
 * Editar salón (panel/mesas?editar=1): modales del admin. El plano para atender está en salon.js.
 *
 * - Mesa: crear o editar en un solo modal; al editar muestra las acciones (estado, orden, desactivar).
 * - Agregar varias: vista previa del rango (T1 a T4, 4 mesas).
 * - Área: crear o renombrar; eliminar solo si no tiene mesas.
 * - Las pestañas de áreas dejan elegida esa área en los botones de crear y en la URL.
 */

function limpiarErrores($form) {
    $form.find('[aria-invalid]').removeAttr('aria-invalid');
    $form.find('[data-error-for]').text('').attr('hidden', true);
    $form.find('[data-form-resumen]').attr('hidden', true);
}

function initMesaModal() {
    const $modal = $('#modal-mesa');

    if (!$modal.length) {
        return;
    }

    const $form = $modal.find('[data-mesa-form]');
    const $titulo = $modal.find('#modal-mesa-title');
    const $acciones = $modal.find('[data-mesa-acciones]');

    function preparar({ titulo, accion, metodo, codigo = '', capacidad = 4, areaId = '' }) {
        $titulo.text(titulo);
        $form.attr('action', accion);
        $form.find('[data-mesa-accion]').val(accion);
        $form.find('[data-mesa-metodo]').val(metodo);
        $form.find('[data-mesa-titulo]').val(titulo);
        $form.find('[data-campo="codigo"]').val(codigo);
        $form.find('[data-campo="capacidad"]').val(capacidad);

        if (areaId) {
            $form.find('[data-campo="area_id"]').val(String(areaId));
        }

        limpiarErrores($form);
    }

    $(document).on('click', '[data-mesa-nueva]', function () {
        preparar({ titulo: 'Nueva mesa', accion: $form.data('url-crear'), metodo: 'POST', areaId: $(this).attr('data-area-id') });
        $acciones.attr('hidden', true);
    });

    $(document).on('click', '[data-mesa-editar]', function () {
        const $mesa = $(this);
        const fuera = $mesa.data('estado') === 'fuera_servicio';
        const activa = Number($mesa.data('activa')) === 1;
        const manual = ['disponible', 'fuera_servicio'].includes($mesa.data('estado'));

        preparar({
            titulo: `Mesa ${$mesa.data('codigo')}`,
            accion: $mesa.data('url'),
            metodo: 'PUT',
            codigo: String($mesa.data('codigo')),
            capacidad: $mesa.data('capacidad'),
            areaId: $mesa.data('area-id'),
        });

        $acciones.find('[data-mesa-accion-form="estado"]').attr('action', $mesa.data('url-estado')).attr('hidden', !activa || !manual);
        $acciones.find('[data-mesa-estado-valor]').val(fuera ? 'disponible' : 'fuera_servicio');
        $acciones.find('[data-mesa-estado-texto]').text(fuera ? 'Volver a poner en servicio' : 'Poner fuera de servicio');

        $acciones.find('[data-mesa-accion-form="mover"]').attr('action', $mesa.data('url-mover'));
        $acciones.find('[data-mesa-subir]').prop('disabled', Number($mesa.data('primera')) === 1);
        $acciones.find('[data-mesa-bajar]').prop('disabled', Number($mesa.data('ultima')) === 1);

        $acciones.find('[data-mesa-accion-form="activa"]').attr('action', $mesa.data('url-activa')).attr('hidden', activa && !manual);
        $acciones.find('[data-mesa-activa-valor]').val(activa ? '0' : '1');
        $acciones
            .find('[data-mesa-activa-texto]')
            .text(activa ? 'Desactivar' : 'Activar')
            .toggleClass('bg-danger-bg text-danger-ink', activa)
            .toggleClass('bg-success-fill text-white', !activa);
        $acciones
            .find('[data-mesa-acciones-ayuda]')
            .text(activa ? 'Una mesa desactivada deja de aparecer en el mapa y de contar para tu plan.' : 'Al activarla vuelve al mapa y cuenta para el límite de tu plan.');

        $acciones.attr('hidden', false);
    });

    if ($modal.attr('data-reabrir') === 'true') {
        $titulo.text($form.find('[data-mesa-titulo]').val());
        $modal[0].showModal();
    }
}

function initVarias() {
    const $modal = $('#modal-varias');

    if (!$modal.length) {
        return;
    }

    const $form = $modal.find('[data-varias-form]');
    const $vista = $form.find('[data-varias-vista]');

    function vistaPrevia() {
        const prefijo = String($form.find('[data-varias-prefijo]').val() || '').trimStart();
        const desde = parseInt($form.find('[data-varias-desde]').val(), 10);
        const hasta = parseInt($form.find('[data-varias-hasta]').val(), 10);

        if (Number.isNaN(desde) || Number.isNaN(hasta) || hasta < desde) {
            $vista.attr('hidden', true);

            return;
        }

        const cantidad = hasta - desde + 1;
        $vista
            .text(cantidad === 1 ? `Se creará la mesa ${prefijo}${desde}.` : `Se crearán ${cantidad} mesas: de ${prefijo}${desde} a ${prefijo}${hasta}.`)
            .attr('hidden', false);
    }

    $form.on('input', 'input', vistaPrevia);

    $(document).on('click', '[data-varias-abrir]', function () {
        const areaId = $(this).attr('data-area-id');

        if (areaId) {
            $form.find('[data-campo="area_id"]').val(String(areaId));
        }

        vistaPrevia();
    });

    vistaPrevia();

    if ($modal.attr('data-reabrir') === 'true') {
        $modal[0].showModal();
    }
}

function initAreaModal() {
    const $modal = $('#modal-area');

    if (!$modal.length) {
        return;
    }

    const $form = $modal.find('[data-area-form]');
    const $titulo = $modal.find('#modal-area-title');

    function preparar({ titulo, accion, metodo, nombre = '' }) {
        $titulo.text(titulo);
        $form.attr('action', accion);
        $form.find('[data-area-accion]').val(accion);
        $form.find('[data-area-metodo]').val(metodo);
        $form.find('[data-area-titulo]').val(titulo);
        $form.find('[data-campo="nombre"]').val(nombre);
        limpiarErrores($form);
    }

    $(document).on('click', '[data-area-nueva]', () => {
        preparar({ titulo: 'Nueva área', accion: $form.data('url-crear'), metodo: 'POST' });
    });

    $(document).on('click', '[data-area-editar]', function () {
        const $boton = $(this);

        preparar({ titulo: `Editar «${$boton.data('nombre')}»`, accion: $boton.data('url'), metodo: 'PUT', nombre: $boton.data('nombre') });
    });

    if ($modal.attr('data-reabrir') === 'true') {
        $titulo.text($form.find('[data-area-titulo]').val());
        $modal[0].showModal();
    }
}

function initEliminarArea() {
    const $modal = $('#modal-eliminar-area');

    if (!$modal.length) {
        return;
    }

    $(document).on('click', '[data-eliminar-area-url]', function () {
        const $boton = $(this);
        const nombre = $boton.data('eliminar-area-nombre');
        const mesas = Number($boton.data('eliminar-area-mesas'));

        $modal.find('[data-eliminar-area-form]').attr('action', $boton.data('eliminar-area-url'));
        $modal.find('[data-eliminar-area-boton]').prop('disabled', mesas > 0);
        $modal
            .find('[data-eliminar-area-texto]')
            .text(
                mesas > 0
                    ? `No puedes eliminar «${nombre}» porque tiene ${mesas} ${mesas === 1 ? 'mesa' : 'mesas'} (contando las desactivadas). Muévelas a otra área o desactiva el área.`
                    : `¿Eliminar el área «${nombre}»? No tiene mesas.`,
            );
    });
}

function initPestanasDeAreas() {
    const $tabs = $('[data-tabs="mesas-areas"]');

    if (!$tabs.length) {
        return;
    }

    $tabs.on('click', '[data-tab]', function () {
        const areaId = String($(this).data('tab')).replace('area-', '');

        $('[data-mesa-nueva], [data-varias-abrir]').not('[data-tab-panel] [data-varias-abrir]').attr('data-area-id', areaId);

        const url = new URL(window.location.href);
        url.searchParams.set('area', areaId);
        window.history.replaceState(null, '', url);
    });
}

$(() => {
    initMesaModal();
    initVarias();
    initAreaModal();
    initEliminarArea();
    initPestanasDeAreas();
});
