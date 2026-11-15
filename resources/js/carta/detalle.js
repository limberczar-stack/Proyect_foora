/**
 * Detalle de producto: variante, grupos de opciones con sus reglas, cantidad, observación y total
 * en vivo. Lo comparten la vitrina pública y la toma de pedidos del salón.
 *
 * Qué pasa con la línea armada lo decide quien lo usa (alAgregar): la vitrina la suma al carrito
 * y el salón al borrador de la ronda. El total que se muestra aquí es solo orientativo: el que vale
 * siempre lo calcula el servidor.
 *
 * Marcado esperado dentro de la hoja: [data-detalle-nombre], -descripcion, -meta, -imagen,
 * -variantes, -grupos, -cantidad, -menos, -mas, -total, -observacion, -contador, -error,
 * -cuerpo y -agregar. Abre el detalle cualquier [data-producto="{id}"].
 */
import $ from 'jquery';
import { abrirHoja } from './hojas';
import { aCentimos, el, OBSERVACION_MAXIMA, soles, solesDeCentimos } from './util';

/**
 * @param {object} config
 * @param {HTMLDialogElement} config.hoja
 * @param {Record<number, object>} config.productos  carta por id (variantes, grupos y disponibilidad)
 * @param {number} config.cantidadMaxima
 * @param {(linea: object, producto: object) => string|null} config.alAgregar  devuelve un error para mostrar, o null si la línea se aceptó
 */
export function initDetalle({ hoja, productos, cantidadMaxima, alAgregar }) {
    const $hoja = $(hoja);
    const detalle = { producto: null, varianteId: null, opciones: new Set(), cantidad: 1 };

    function elegidasDe(grupo) {
        return grupo.opciones.filter((opcion) => detalle.opciones.has(opcion.id));
    }

    function gruposSinCumplir() {
        return detalle.producto.grupos.filter((grupo) => elegidasDe(grupo).length < grupo.minimo);
    }

    function totalDetalleCentimos() {
        const { producto, varianteId, opciones, cantidad } = detalle;
        const variante = producto.variantes.find((v) => v.id === varianteId);

        if (producto.tipo === 'variantes' && !variante) {
            return null;
        }

        const extras = producto.grupos
            .flatMap((grupo) => grupo.opciones)
            .filter((opcion) => opciones.has(opcion.id))
            .reduce((suma, opcion) => suma + aCentimos(opcion.precio_extra), 0);

        return (aCentimos(variante?.precio ?? producto.precio) + extras) * cantidad;
    }

    function pintarTotalDetalle() {
        const total = totalDetalleCentimos();

        $hoja.find('[data-detalle-cantidad]').text(detalle.cantidad);
        $hoja.find('[data-detalle-menos]').prop('disabled', detalle.cantidad <= 1);
        $hoja.find('[data-detalle-mas]').prop('disabled', detalle.cantidad >= cantidadMaxima);
        $hoja.find('[data-detalle-total]').text(total === null ? '' : solesDeCentimos(total));
    }

    /** Título de bloque con su regla: nombre a la izquierda, regla a la derecha. */
    function cabeceraBloque(titulo, regla, obligatorio) {
        const $cabecera = el('legend', 'flex w-full items-baseline justify-between gap-3 text-sm font-semibold');

        $cabecera.append(el('span', 'min-w-0', titulo));
        $cabecera.append(el('span', `shrink-0 text-xs font-medium ${obligatorio ? 'text-brand-700' : 'text-muted'}`, regla));

        return $cabecera;
    }

    function filaElegible({ tipo, nombre, valor, marcado, agotado, texto, detalleTexto, atributo }) {
        const $fila = el('label', `flex min-h-12 items-center gap-3 border-b border-rule last:border-b-0 ${agotado ? 'cursor-not-allowed text-faint' : 'cursor-pointer'}`);
        const $control = el('input', 'size-[1.125rem] shrink-0 accent-brand-700')
            .attr({ type: tipo, name: nombre, value: valor, [atributo]: valor })
            .prop({ checked: marcado, disabled: agotado });

        return $fila.append($control, el('span', 'min-w-0 flex-1 text-sm', texto), el('span', 'num shrink-0 text-[0.8125rem] text-muted', detalleTexto));
    }

    function errorDeBloque() {
        return el('p', 'mt-1 text-[0.8125rem] font-medium text-danger-ink').attr('data-bloque-error', '').prop('hidden', true);
    }

    function pintarVariantes() {
        const { producto } = detalle;
        const $contenedor = $hoja.find('[data-detalle-variantes]').empty();

        if (producto.tipo !== 'variantes') {
            return;
        }

        const $bloque = el('fieldset', 'mt-5').attr('data-bloque', 'variante');

        $bloque.append(cabeceraBloque('Elige una opción', 'Obligatorio', true));
        producto.variantes.forEach((variante) => {
            $bloque.append(
                filaElegible({
                    tipo: 'radio',
                    nombre: 'detalle-variante',
                    valor: variante.id,
                    marcado: false,
                    agotado: !variante.disponible,
                    atributo: 'data-variante',
                    texto: variante.nombre,
                    detalleTexto: variante.disponible ? soles(variante.precio) : 'Agotado',
                }),
            );
        });

        $contenedor.append($bloque.append(errorDeBloque()));
    }

    function pintarGrupos() {
        const $contenedor = $hoja.find('[data-detalle-grupos]').empty();

        detalle.producto.grupos.forEach((grupo) => {
            const $bloque = el('fieldset', 'mt-5').attr('data-bloque', grupo.id);

            $bloque.append(cabeceraBloque(grupo.nombre, grupo.obligatorio ? `${grupo.regla} · Obligatorio` : grupo.regla, grupo.obligatorio));

            grupo.opciones.forEach((opcion) => {
                const costo = aCentimos(opcion.precio_extra);

                $bloque.append(
                    filaElegible({
                        // Un radio no se puede desmarcar: en un grupo opcional se usa casilla.
                        tipo: grupo.maximo === 1 && grupo.obligatorio ? 'radio' : 'checkbox',
                        nombre: `detalle-grupo-${grupo.id}`,
                        valor: opcion.id,
                        marcado: false,
                        agotado: !opcion.disponible,
                        atributo: 'data-opcion',
                        texto: opcion.nombre,
                        detalleTexto: !opcion.disponible ? 'Agotado' : costo > 0 ? `+${solesDeCentimos(costo)}` : '',
                    }).attr('data-grupo', grupo.id),
                );
            });

            $contenedor.append($bloque.append(errorDeBloque()));
        });
    }

    function mostrarErrorBloque(id, mensaje) {
        $hoja.find(`[data-bloque="${id}"] [data-bloque-error]`).text(mensaje ?? '').prop('hidden', !mensaje);
    }

    function abrirDetalle(id) {
        const producto = productos[id];

        if (!producto) {
            return;
        }

        Object.assign(detalle, { producto, varianteId: null, opciones: new Set(), cantidad: 1 });

        const personas = producto.personas ? `Para ${producto.personas} ${producto.personas === 1 ? 'persona' : 'personas'}` : '';

        $hoja.find('[data-detalle-nombre]').text(producto.nombre);
        $hoja.find('[data-detalle-descripcion]').text(producto.descripcion ?? '').prop('hidden', !producto.descripcion);
        $hoja.find('[data-detalle-meta]').text([personas, ...producto.etiquetas].filter(Boolean).join(' · '));
        $hoja.find('[data-detalle-imagen]').attr('src', producto.imagen ?? '').prop('hidden', !producto.imagen);
        $hoja.find('[data-detalle-observacion]').val('');
        $hoja.find('[data-detalle-contador]').text(`0/${OBSERVACION_MAXIMA}`);
        $hoja.find('[data-detalle-error]').prop('hidden', true).text('');

        pintarVariantes();
        pintarGrupos();
        pintarTotalDetalle();

        abrirHoja(hoja);
        $hoja.find('[data-detalle-cuerpo]').scrollTop(0);
    }

    function cambiarOpcion(control) {
        const grupo = detalle.producto.grupos.find((g) => String(g.id) === $(control).closest('label').attr('data-grupo'));
        const id = Number(control.value);

        mostrarErrorBloque(grupo.id, null);

        if (!control.checked) {
            detalle.opciones.delete(id);
            return;
        }

        if (grupo.maximo === 1) {
            elegidasDe(grupo).forEach((opcion) => detalle.opciones.delete(opcion.id));
            $(control).closest('fieldset').find('input[data-opcion]').not(control).prop('checked', false);
        } else if (grupo.maximo !== null && elegidasDe(grupo).length >= grupo.maximo) {
            control.checked = false;
            mostrarErrorBloque(grupo.id, `Solo puedes elegir hasta ${grupo.maximo}.`);
            return;
        }

        detalle.opciones.add(id);
    }

    function agregarDetalle() {
        const { producto, varianteId, opciones, cantidad } = detalle;
        const faltaVariante = producto.tipo === 'variantes' && !varianteId;
        const faltantes = gruposSinCumplir();

        if (faltaVariante || faltantes.length) {
            if (faltaVariante) {
                mostrarErrorBloque('variante', 'Elige una opción para continuar.');
            }

            faltantes.forEach((grupo) => mostrarErrorBloque(grupo.id, `${grupo.regla} para continuar.`));
            $hoja.find(`[data-bloque="${faltaVariante ? 'variante' : faltantes[0].id}"]`)[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
        }

        const linea = {
            producto_id: producto.id,
            variante_id: varianteId,
            opciones: [...opciones],
            cantidad,
            observacion: $hoja.find('[data-detalle-observacion]').val().trim().slice(0, OBSERVACION_MAXIMA),
        };

        const error = alAgregar(linea, producto);

        if (error) {
            $hoja.find('[data-detalle-error]').text(error).prop('hidden', false);
            return;
        }

        hoja.close();
    }

    $(document).on('click', '[data-producto]', function () {
        abrirDetalle(Number($(this).attr('data-producto')));
    });

    $hoja.on('change', 'input[data-variante]', function () {
        detalle.varianteId = Number(this.value);
        mostrarErrorBloque('variante', null);
        pintarTotalDetalle();
    });

    $hoja.on('change', 'input[data-opcion]', function () {
        cambiarOpcion(this);
        pintarTotalDetalle();
    });

    $hoja.on('click', '[data-detalle-menos]', () => {
        detalle.cantidad = Math.max(1, detalle.cantidad - 1);
        pintarTotalDetalle();
    });

    $hoja.on('click', '[data-detalle-mas]', () => {
        detalle.cantidad = Math.min(cantidadMaxima, detalle.cantidad + 1);
        pintarTotalDetalle();
    });

    $hoja.on('input', '[data-detalle-observacion]', function () {
        $hoja.find('[data-detalle-contador]').text(`${this.value.length}/${OBSERVACION_MAXIMA}`);
    });

    $hoja.on('click', '[data-detalle-agregar]', agregarDetalle);
}
