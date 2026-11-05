<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restauranteA;

    private Restaurante $restauranteB;

    private Mesa $mesaA;

    private Mesa $mesaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restauranteA = Restaurante::factory()->create();
        $this->restauranteB = Restaurante::factory()->create();

        $this->mesaA = Mesa::factory()->for(Area::factory()->for($this->restauranteA)->state(['nombre' => 'Salón A']))->create(['restaurante_id' => $this->restauranteA->id, 'codigo' => 'A1']);
        $this->mesaB = Mesa::factory()->for(Area::factory()->for($this->restauranteB)->state(['nombre' => 'Salón B']))->create(['restaurante_id' => $this->restauranteB->id, 'codigo' => 'B1']);
    }

    public function test_staff_member_only_sees_their_own_restaurant_data(): void
    {
        $this->actingAs(User::factory()->cajero($this->restauranteA)->create());

        $this->assertSame(['A1'], Mesa::pluck('codigo')->all());
        $this->assertSame([$this->restauranteA->id], Area::pluck('restaurante_id')->unique()->values()->all());
        $this->assertNull(Mesa::find($this->mesaB->id));
    }

    public function test_data_from_another_restaurant_cannot_be_updated_through_the_model(): void
    {
        $this->actingAs(User::factory()->admin($this->restauranteA)->create());

        $actualizadas = Mesa::whereKey($this->mesaB->id)->update(['estado' => 'fuera_servicio']);

        $this->assertSame(0, $actualizadas);
        $this->assertSame('disponible', $this->mesaB->fresh()->estado);
    }

    public function test_restaurante_id_is_assigned_automatically_on_create(): void
    {
        $this->actingAs(User::factory()->admin($this->restauranteA)->create());

        $area = Area::create(['nombre' => 'Patio']);

        $this->assertSame($this->restauranteA->id, $area->restaurante_id);
    }

    public function test_superadmin_sees_every_restaurant(): void
    {
        $this->actingAs(User::factory()->superadmin()->create());

        $this->assertEqualsCanonicalizing(['A1', 'B1'], Mesa::pluck('codigo')->all());
    }

    public function test_without_a_session_nothing_is_filtered(): void
    {
        $this->assertSame(2, Mesa::count());
    }
}
