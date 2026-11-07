<?php

namespace Tests\Feature\Vitrina;

use App\Rules\SlugDisponible;
use App\Support\SlugsReservados;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SlugDisponibleTest extends TestCase
{
    private function falla(mixed $slug): bool
    {
        return Validator::make(['slug' => $slug], ['slug' => [new SlugDisponible]])->fails();
    }

    public function test_every_reserved_slug_is_rejected(): void
    {
        foreach (SlugsReservados::LISTA as $slug) {
            $this->assertTrue($this->falla($slug), "El slug {$slug} debería estar reservado.");
        }

        $this->assertSame(
            'La dirección «panel» está reservada. Elige otra.',
            Validator::make(['slug' => 'panel'], ['slug' => [new SlugDisponible]])->errors()->first('slug'),
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function formatosInvalidos(): array
    {
        return [
            'mayúsculas' => ['El-Buen-Sabor'],
            'espacios' => ['el buen sabor'],
            'tilde' => ['pollería'],
            'guion al inicio' => ['-pollo'],
            'guion al final' => ['pollo-'],
            'doble guion' => ['pollo--brasa'],
            'barra' => ['pollo/brasa'],
            'punto' => ['pollo.brasa'],
            'salto de línea' => ["pollo\n"],
            'no es texto' => [['pollo']],
            'demasiado largo' => [str_repeat('a', SlugsReservados::LONGITUD_MAXIMA + 1)],
        ];
    }

    #[DataProvider('formatosInvalidos')]
    public function test_invalid_formats_are_rejected(mixed $slug): void
    {
        $this->assertTrue($this->falla($slug));
    }

    public function test_valid_slugs_are_accepted(): void
    {
        foreach (['el-buen-sabor', 'la-bahia', 'chifa-dragon-dorado', 'pollos24', 'panel-norte', 'r2'] as $slug) {
            $this->assertFalse($this->falla($slug), "El slug {$slug} debería aceptarse.");
        }
    }
}
