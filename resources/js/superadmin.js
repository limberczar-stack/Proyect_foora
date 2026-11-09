/**
 * FOORA: sección del superadmin (jQuery).
 *
 * El envío, los errores y el reintento los hacen los formularios form[data-ajax-form] de configuracion.js.
 * Aquí va solo lo propio: sugerir el slug, mostrar una vez la contraseña del alta, proponer el precio
 * del plan y recargar el detalle tras cada acción.
 */
import $ from 'jquery';

function sugerirSlug(nombre) {
    return nombre
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 140);
}

function initAlta() {
    const $raiz = $('[data-alta-restaurante]');

    if (!$raiz.length) {
        return;
    }

    const $slug = $raiz.find('[data-alta-slug]');
    const modal = document.getElementById('modal-clave-alta');
    let slugEditado = false;
    let urlDetalle = null;

    $slug.on('input', () => {
        slugEditado = $slug.val() !== '';
    });

    $raiz.find('[data-alta-nombre]').on('input', function () {
        if (!slugEditado) {
            $slug.val(sugerirSlug(this.value));
        }
    });

    // La contraseña solo vive en este modal mientras está abierto.
    $raiz.find('form').on('foora:guardado', (evento, respuesta) => {
        const $modal = $(modal);

        urlDetalle = respuesta.url_detalle;
        $modal.find('[data-clave-mensaje]').text(respuesta.mensaje || '');
        $modal.find('[data-clave-nombre]').text(respuesta.admin?.name || '');
        $modal.find('[data-clave-email]').text(respuesta.admin?.email || '');
        $modal.find('[data-clave-valor]').text(respuesta.password_temporal);
        $modal.find('[data-copiar]').data('copiar', respuesta.password_temporal);
        modal.showModal();
    });

    $(modal).on('close', () => {
        $(modal).find('[data-clave-valor]').text('');
        $(modal).find('[data-copiar]').removeData('copiar');

        if (urlDetalle) {
            window.location.assign(urlDetalle);
        }
    });
}

function initDetalle() {
    $(document).on('foora:guardado', 'form[data-recargar]', () => window.location.reload());

    // Al cambiar de plan se propone su precio de lista; se puede pactar otro.
    $('[data-plan-select]').on('change', function () {
        const precio = $(this).find('option:selected').data('precio');

        if (precio !== undefined) {
            $('[data-plan-precio]').val(precio);
        }
    });
}

$(() => {
    initAlta();
    initDetalle();
});
