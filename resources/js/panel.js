/**
 * FOORA: interacciones del panel (solo UI, sin lógica de negocio).
 *
 * - Menú lateral en celular y tablet: abrir, cerrar con Esc o fondo, foco al primer enlace y de vuelta.
 * - Menús desplegables (campana, usuario): clic fuera y Esc cierran.
 * - Pestañas: patrón WAI-ARIA con flechas, Inicio y Fin.
 * - Modales sobre <dialog>: abrir, cerrar, clic en el fondo, foco de vuelta al disparador.
 * - Formularios con estado de carga al enviar (login).
 */
import $ from 'jquery';

const DESKTOP_QUERY = window.matchMedia('(min-width: 1024px)');

/* -------------------------------------------------------------------------- */
/* Menú lateral                                                                */
/* -------------------------------------------------------------------------- */

function initSidebar() {
    const $sidebar = $('[data-sidebar]');
    const $overlay = $('[data-nav-overlay]');
    const $toggle = $('[data-nav-toggle]');

    if (!$sidebar.length) {
        return;
    }

    let lastFocused = null;

    const isOpen = () => $sidebar.attr('data-open') === 'true';

    function open() {
        lastFocused = document.activeElement;
        $sidebar.attr('data-open', 'true').removeClass('invisible -translate-x-full').addClass('visible translate-x-0');
        $overlay.removeClass('invisible opacity-0').addClass('visible opacity-100');
        $toggle.attr('aria-expanded', 'true');
        $('body').addClass('overflow-hidden');
        $sidebar.find('a, button').filter(':visible').first().trigger('focus');
    }

    function close({ restoreFocus = true } = {}) {
        $sidebar.attr('data-open', 'false').removeClass('visible translate-x-0').addClass('invisible -translate-x-full');
        $overlay.removeClass('visible opacity-100').addClass('invisible opacity-0');
        $toggle.attr('aria-expanded', 'false');
        $('body').removeClass('overflow-hidden');

        if (restoreFocus && lastFocused) {
            $(lastFocused).trigger('focus');
        }
    }

    $toggle.on('click', () => (isOpen() ? close() : open()));
    $('[data-nav-close]').on('click', () => close());
    $overlay.on('click', () => close());

    $(document).on('keydown', (event) => {
        if (event.key === 'Escape' && isOpen() && !DESKTOP_QUERY.matches) {
            close();
        }
    });

    // Mantiene el foco dentro del panel abierto en celular.
    $sidebar.on('keydown', (event) => {
        if (event.key !== 'Tab' || !isOpen() || DESKTOP_QUERY.matches) {
            return;
        }

        const $focusables = $sidebar.find('a[href], button:not([disabled])').filter(':visible');
        const first = $focusables.first()[0];
        const last = $focusables.last()[0];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    // Al pasar a escritorio, el panel queda fijo y sin estado móvil.
    DESKTOP_QUERY.addEventListener('change', (event) => {
        if (event.matches && isOpen()) {
            close({ restoreFocus: false });
        }
    });
}

/* -------------------------------------------------------------------------- */
/* Menús desplegables                                                          */
/* -------------------------------------------------------------------------- */

function initDropdowns() {
    function closeAll(except = null) {
        $('[data-dropdown]').each(function () {
            if (this === except) {
                return;
            }

            $(this).find('[data-dropdown-menu]').attr('hidden', true);
            $(this).find('[data-dropdown-trigger]').attr('aria-expanded', 'false');
        });
    }

    $(document).on('click', '[data-dropdown-trigger]', function (event) {
        event.stopPropagation();

        const $wrapper = $(this).closest('[data-dropdown]');
        const $menu = $wrapper.find('[data-dropdown-menu]');
        const willOpen = $menu.is('[hidden]');

        closeAll($wrapper[0]);
        $menu.attr('hidden', !willOpen);
        $(this).attr('aria-expanded', String(willOpen));
    });

    $(document).on('click', (event) => {
        if (!$(event.target).closest('[data-dropdown-menu]').length) {
            closeAll();
        }
    });

    $(document).on('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const $openMenu = $('[data-dropdown-menu]:not([hidden])');

        if ($openMenu.length) {
            const $trigger = $openMenu.closest('[data-dropdown]').find('[data-dropdown-trigger]');
            closeAll();
            $trigger.trigger('focus');
        }
    });
}

/* -------------------------------------------------------------------------- */
/* Pestañas                                                                    */
/* -------------------------------------------------------------------------- */

function initTabs() {
    $('[data-tabs]').each(function () {
        const $root = $(this);
        const $tabs = $root.find('[role="tab"]');

        function select($tab, { focus = true } = {}) {
            if ($tab.attr('aria-disabled') === 'true') {
                return;
            }

            const key = $tab.data('tab');

            $tabs.each(function () {
                const isActive = $(this).data('tab') === key;

                $(this)
                    .attr({ 'aria-selected': String(isActive), tabindex: isActive ? '0' : '-1' })
                    .toggleClass('font-medium text-ink shadow-[inset_0_-2px_0_var(--color-brand-700)]', isActive)
                    .toggleClass('text-muted hover:text-ink', !isActive)
                    .find('.num')
                    .toggleClass('bg-brand-100 text-brand-800', isActive)
                    .toggleClass('bg-raised text-muted', !isActive);
            });

            $root.find('[data-tab-panel]').each(function () {
                $(this).attr('hidden', $(this).data('tab-panel') !== key);
            });

            if (focus) {
                $tab.trigger('focus');
            }
        }

        $tabs.on('click', function () {
            select($(this), { focus: false });
        });

        $tabs.on('keydown', function (event) {
            const $enabled = $tabs.filter(':not([aria-disabled="true"])');
            const index = $enabled.index(this);
            const keys = {
                ArrowRight: index + 1,
                ArrowLeft: index - 1,
                Home: 0,
                End: $enabled.length - 1,
            };

            if (!(event.key in keys)) {
                return;
            }

            event.preventDefault();
            const next = (keys[event.key] + $enabled.length) % $enabled.length;
            select($enabled.eq(next));
        });
    });
}

/* -------------------------------------------------------------------------- */
/* Modales                                                                     */
/* -------------------------------------------------------------------------- */

function initModals() {
    $(document).on('click', '[data-modal-open]', function (event) {
        event.preventDefault();

        const dialog = document.getElementById($(this).data('modal-open'));

        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }

        $(dialog).data('returnFocus', this);
        dialog.showModal();
    });

    $(document).on('click', '[data-modal-close]', function () {
        $(this).closest('dialog')[0]?.close();
    });

    // Clic en el fondo (fuera de la caja del diálogo) cierra.
    $(document).on('click', 'dialog[data-modal]', function (event) {
        if (event.target !== this) {
            return;
        }

        const rect = this.getBoundingClientRect();
        const inside =
            event.clientX >= rect.left &&
            event.clientX <= rect.right &&
            event.clientY >= rect.top &&
            event.clientY <= rect.bottom;

        if (!inside) {
            this.close();
        }
    });

    $('dialog[data-modal]').on('close', function () {
        const trigger = $(this).data('returnFocus');

        if (trigger) {
            trigger.focus();
        }
    });
}

/* -------------------------------------------------------------------------- */
/* Controles inactivos y estado del local                                      */
/* -------------------------------------------------------------------------- */

function initInactiveControls() {
    // Botones en carga y enlaces deshabilitados no deben ejecutar su acción.
    $(document).on('click', '[aria-disabled="true"]', (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
    });
}

/**
 * Formularios con data-loading-form: al enviar, el botón [data-submit] pasa a "cargando"
 * (spinner visible, texto alternativo, no se puede enviar dos veces).
 */
function initLoadingForms() {
    $(document).on('submit', 'form[data-loading-form]', function () {
        const $button = $(this).find('[data-submit]');

        if ($button.attr('aria-busy') === 'true') {
            return false;
        }

        $button.attr({ 'aria-busy': 'true', 'aria-disabled': 'true' }).addClass('cursor-progress');
        $button.find('[data-submit-spinner]').removeAttr('hidden');
        $button.find('[data-submit-icon]').attr('hidden', true);
        $button.find('[data-submit-label]').text($button.data('loading-text') || 'Cargando…');

        return true;
    });
}

$(() => {
    initInactiveControls();
    initSidebar();
    initDropdowns();
    initTabs();
    initModals();
    initLoadingForms();
});
