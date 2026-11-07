<?php

namespace App\Support;

/**
 * Slugs que un restaurante no puede usar porque la vitrina vive en la raíz: /{slug}.
 *
 * Una ruta nueva de primer nivel debe agregarse aquí.
 */
final class SlugsReservados
{
    /** Minúsculas, números y guiones simples entre palabras. */
    public const FORMATO = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const LONGITUD_MAXIMA = 140;

    /**
     * @var list<string>
     */
    public const LISTA = [
        'admin',
        'api',
        'assets',
        'ayuda',
        'build',
        'carrito',
        'explorar',
        'favoritos',
        'login',
        'logout',
        'panel',
        'password',
        'pedidos',
        'perfil',
        'privacidad',
        'r',
        'register',
        'reservas',
        'storage',
        'terminos',
        'up',
    ];

    public static function incluye(string $slug): bool
    {
        return in_array($slug, self::LISTA, true);
    }

    /**
     * Restricción de la ruta: el formato, sin ningún slug reservado.
     * El lookahead acepta "/" además del final para cubrir también /{slug}/carrito/validar.
     */
    public static function patronRuta(): string
    {
        return '(?!(?:'.implode('|', self::LISTA).')(?:/|$))'.self::FORMATO;
    }
}
