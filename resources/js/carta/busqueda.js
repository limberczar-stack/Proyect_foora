/**
 * Carta: búsqueda por nombre (sin tildes) y chips de categorías que siguen el scroll.
 *
 * Trabaja sobre el marcado de la carta: [data-buscar], [data-producto-item][data-nombre],
 * [data-seccion], [data-subseccion], [data-chips], [data-chip], [data-subchips] y [data-sin-resultados].
 */
import $ from 'jquery';
import { sinTildes } from './util';

export function initBusqueda() {
    const $buscar = $('[data-buscar]');
    const $items = $('[data-producto-item]');
    const $vacio = $('[data-sin-resultados]');

    $items.each(function () {
        $(this).data('busqueda', sinTildes($(this).attr('data-nombre')));
    });

    $buscar.on('input', function () {
        const termino = sinTildes(this.value.trim());
        let visibles = 0;

        $items.each(function () {
            const coincide = $(this).data('busqueda').includes(termino);
            $(this).prop('hidden', !coincide);
            visibles += coincide ? 1 : 0;
        });

        $('[data-subseccion], [data-seccion]').each(function () {
            $(this).prop('hidden', $(this).find('[data-producto-item]:not([hidden])').length === 0);
        });

        $('[data-chips]').prop('hidden', termino !== '');
        $vacio.prop('hidden', visibles > 0);
        $vacio.find('[data-sin-resultados-texto]').text(this.value.trim());
    });
}

export function initChips() {
    const $chips = $('[data-chip]');

    if (!$chips.length) {
        return;
    }

    function activar(id) {
        const $activo = $chips.filter(`[data-chip="${id}"]`);

        $chips.removeAttr('aria-current');
        $activo.attr('aria-current', 'true');
        $('[data-subchips]').each(function () {
            $(this).prop('hidden', String($(this).data('subchips')) !== String(id));
        });

        // Solo se mueve la fila de chips: scrollIntoView arrastraría también la página.
        const fila = $activo.closest('ul')[0];
        const chip = $activo[0];

        if (fila && chip) {
            fila.scrollTo({ left: chip.offsetLeft - fila.offsetLeft - 16, behavior: 'smooth' });
        }
    }

    activar($chips.first().data('chip'));

    if (!('IntersectionObserver' in window)) {
        return;
    }

    const observador = new IntersectionObserver(
        (entradas) => {
            entradas.filter((entrada) => entrada.isIntersecting).forEach((entrada) => activar($(entrada.target).data('seccion')));
        },
        { rootMargin: '-96px 0px -70% 0px' },
    );

    $('[data-seccion]').each(function () {
        observador.observe(this);
    });
}
