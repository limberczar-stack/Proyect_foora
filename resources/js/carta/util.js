/**
 * Utilidades de la carta compartidas por la vitrina pública y la toma de pedidos del salón.
 *
 * Los nombres y descripciones los escribe cada restaurante: todo dato se pinta con .text() o
 * .val(), nunca con .html() ni concatenado en una plantilla.
 */
import $ from 'jquery';

export const OBSERVACION_MAXIMA = 200;

export const aCentimos = (monto) => Math.round(Number(monto) * 100);

const ESPACIO_FIJO = String.fromCharCode(160);

export const soles = (monto) => `S/${ESPACIO_FIJO}${Number(monto).toFixed(2)}`;

export const solesDeCentimos = (centimos) => soles(centimos / 100);

export const sinTildes = (texto) =>
    String(texto)
        .normalize('NFD')
        .replace(/\p{Mn}/gu, '')
        .toLowerCase();

/** Crea un elemento con clases y texto. El texto siempre se asigna con .text(). */
export function el(etiqueta, clases = '', texto = null) {
    const $elemento = $(document.createElement(etiqueta)).addClass(clases);

    return texto === null ? $elemento : $elemento.text(texto);
}

/** localStorage puede no existir o lanzar error (modo privado, bloqueado): se sigue en memoria. */
export const almacen = {
    leer(clave) {
        try {
            return window.localStorage.getItem(clave);
        } catch {
            return null;
        }
    },
    escribir(clave, valor) {
        try {
            window.localStorage.setItem(clave, valor);
        } catch {
            // Sin almacenamiento, lo guardado dura lo que dure la página.
        }
    },
};

export const esIdValido = (valor) => Number.isInteger(valor) && valor > 0;

/**
 * Descarta lo que no tenga la forma esperada: el contenido guardado no es de confianza.
 *
 * @param {{lineasMaximas: number, cantidadMaxima: number}} limites
 */
export function sanearLineas(valor, { lineasMaximas, cantidadMaxima }) {
    if (!valor || !Array.isArray(valor.lineas)) {
        return [];
    }

    return valor.lineas
        .filter((linea) => linea && esIdValido(linea.producto_id) && Number.isInteger(linea.cantidad) && linea.cantidad >= 1)
        .slice(0, lineasMaximas)
        .map((linea) => ({
            producto_id: linea.producto_id,
            variante_id: esIdValido(linea.variante_id) ? linea.variante_id : null,
            opciones: Array.isArray(linea.opciones) ? [...new Set(linea.opciones.filter(esIdValido))] : [],
            cantidad: Math.min(linea.cantidad, cantidadMaxima),
            observacion: typeof linea.observacion === 'string' ? linea.observacion.slice(0, OBSERVACION_MAXIMA) : '',
        }));
}

/** Dos líneas iguales (mismo producto, variante, opciones y nota) se suman en vez de repetirse. */
export function claveLinea(linea) {
    return JSON.stringify([linea.producto_id, linea.variante_id, [...linea.opciones].sort((a, b) => a - b), linea.observacion]);
}

/** Nombre y detalle (variante y opciones) de una línea, con los datos de la carta ya cargada. */
export function textoLineaLocal(productos, linea) {
    const producto = productos[linea.producto_id];

    if (!producto) {
        return { nombre: 'Producto no disponible', detalle: '' };
    }

    const variante = producto.variantes.find((v) => v.id === linea.variante_id);
    const opciones = producto.grupos.flatMap((g) => g.opciones).filter((o) => linea.opciones.includes(o.id));

    return {
        nombre: producto.nombre,
        detalle: [variante?.nombre, ...opciones.map((o) => o.nombre)].filter(Boolean).join(' · '),
    };
}

/** crypto.randomUUID solo existe en HTTPS; getRandomValues también funciona en HTTP. */
export function nuevaClaveIdempotencia() {
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));

    return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}
