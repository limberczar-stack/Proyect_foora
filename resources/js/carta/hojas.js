/**
 * Hojas: <dialog data-hoja> que en celular es una hoja inferior (con arrastre para cerrar)
 * y en escritorio un modal. Se cierran con [data-hoja-cerrar], Esc o clic en el fondo.
 */
import $ from 'jquery';

const VELOCIDAD_CIERRE = 0.11;

export function abrirHoja(hoja) {
    if (typeof hoja.showModal !== 'function' || hoja.open) {
        return;
    }

    hoja.showModal();
    $('html').addClass('overflow-hidden');
}

export function initHojas() {
    $(document).on('click', '[data-hoja-cerrar]', function () {
        $(this).closest('dialog')[0].close();
    });

    // Clic en el fondo (fuera de la caja del diálogo) cierra.
    $(document).on('click', 'dialog[data-hoja]', function (evento) {
        if (evento.target !== this) {
            return;
        }

        const caja = this.getBoundingClientRect();
        const dentro =
            evento.clientX >= caja.left && evento.clientX <= caja.right && evento.clientY >= caja.top && evento.clientY <= caja.bottom;

        if (!dentro) {
            this.close();
        }
    });

    $('dialog[data-hoja]').on('close', () => {
        if (!$('dialog[data-hoja][open]').length) {
            $('html').removeClass('overflow-hidden');
        }
    });

    $('[data-hoja-asa]').each(function () {
        initArrastre(this);
    });
}

/** Arrastrar hacia abajo cierra. Basta un gesto rápido, sin importar la distancia. */
function initArrastre(asa) {
    const hoja = asa.closest('dialog');
    const esHojaInferior = window.matchMedia('(max-width: 639px)');
    let inicio = null;

    asa.addEventListener('pointerdown', (evento) => {
        if (!esHojaInferior.matches || inicio || evento.target.closest('button')) {
            return;
        }

        inicio = { y: evento.clientY, tiempo: performance.now(), puntero: evento.pointerId };
        asa.setPointerCapture(evento.pointerId);
        hoja.style.transition = 'none';
    });

    asa.addEventListener('pointermove', (evento) => {
        if (!inicio || evento.pointerId !== inicio.puntero) {
            return;
        }

        const distancia = evento.clientY - inicio.y;

        // Hacia arriba hay fricción en vez de un tope seco.
        hoja.style.transform = `translateY(${distancia > 0 ? distancia : distancia * 0.15}px)`;
    });

    function soltar(evento) {
        if (!inicio || evento.pointerId !== inicio.puntero) {
            return;
        }

        const distancia = evento.clientY - inicio.y;
        const velocidad = Math.abs(distancia) / (performance.now() - inicio.tiempo);
        const cerrar = distancia > 0 && (distancia > hoja.offsetHeight * 0.3 || velocidad > VELOCIDAD_CIERRE);

        inicio = null;
        hoja.style.transition = '';
        hoja.style.transform = '';

        if (cerrar) {
            hoja.close();
        }
    }

    asa.addEventListener('pointerup', soltar);
    asa.addEventListener('pointercancel', soltar);
}
