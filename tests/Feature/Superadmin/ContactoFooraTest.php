<?php

namespace Tests\Feature\Superadmin;

use App\Models\Restaurante;
use App\Models\Suscripcion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactoFooraTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'America/Lima'));

        config(['foora.contacto_whatsapp' => '+51 987 654 321', 'foora.contacto_email' => 'soporte@foora.test']);

        $this->restaurante = Restaurante::factory()->create(['nombre' => 'Pollería Doña & Hijos']);
        $this->actingAs(User::factory()->admin($this->restaurante)->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function avisos(): array
    {
        return [
            'por vencer' => ['activa', '2026-11-15', 'Tu suscripción vence en 5 días'],
            'en gracia' => ['vencida', '2026-11-08', 'Te quedan 3 días de gracia'],
            'fuera de operación' => ['vencida', '2026-10-01', 'Tu suscripción venció'],
            'cancelada' => ['cancelada', '2026-12-01', 'Tu suscripción está cancelada'],
        ];
    }

    #[DataProvider('avisos')]
    public function test_each_notice_links_to_whatsapp_with_the_local_name_and_to_email(string $estado, string $venceEl, string $texto): void
    {
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => $estado, 'vence_el' => $venceEl]);

        $this->get(route('panel.inicio'))
            ->assertOk()
            ->assertSee($texto)
            ->assertSee('https://wa.me/51987654321?text='.rawurlencode('Hola FOORA, soy del local Pollería Doña & Hijos y necesito ayuda con mi cuenta.'), false)
            ->assertSee('mailto:soporte@foora.test?subject='.rawurlencode('Cuenta del local Pollería Doña & Hijos'), false);
    }

    public function test_a_suspended_local_also_gets_the_contact(): void
    {
        $this->restaurante->update(['activo' => false]);

        $this->get(route('panel.inicio'))
            ->assertSee('Tu local está suspendido')
            ->assertSee('https://wa.me/51987654321', false)
            ->assertSee('mailto:soporte@foora.test', false);
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function contactosSinEnlace(): array
    {
        return [
            'vacíos' => ['', ''],
            'sin definir' => [null, null],
            'inválidos' => ['123', 'no-es-un-correo'],
        ];
    }

    #[DataProvider('contactosSinEnlace')]
    public function test_without_valid_contact_the_notice_shows_no_broken_links(?string $whatsapp, ?string $email): void
    {
        config(['foora.contacto_whatsapp' => $whatsapp, 'foora.contacto_email' => $email]);
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-10-01']);

        $this->get(route('panel.inicio'))
            ->assertOk()
            ->assertSee('Contacta a FOORA')
            ->assertDontSee('wa.me', false)
            ->assertDontSee('mailto:', false);
    }

    public function test_only_the_valid_contact_gets_a_link(): void
    {
        config(['foora.contacto_whatsapp' => '']);
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'vencida', 'vence_el' => '2026-10-01']);

        $this->get(route('panel.inicio'))
            ->assertDontSee('wa.me', false)
            ->assertSee('mailto:soporte@foora.test', false);
    }

    public function test_there_is_no_contact_when_there_is_nothing_to_notify(): void
    {
        Suscripcion::factory()->create(['restaurante_id' => $this->restaurante->id, 'estado' => 'activa', 'vence_el' => '2027-01-01']);

        $this->get(route('panel.inicio'))
            ->assertOk()
            ->assertDontSee('wa.me', false)
            ->assertDontSee('mailto:', false);
    }
}
