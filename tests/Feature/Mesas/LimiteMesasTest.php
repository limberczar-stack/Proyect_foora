<?php

namespace Tests\Feature\Mesas;

use App\Models\Area;
use App\Models\Mesa;
use App\Models\Plan;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * planes.max_mesas: cuenta solo las mesas activas (aunque su área esté desactivada) y se aplica
 * al crear, al agregar varias y al reactivar. Sin límite en el plan, no hay tope.
 */
class LimiteMesasTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private Area $salon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurante = Restaurante::factory()->conModulos('mesas')->create();
        $this->salon = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Salón']);
        $this->actingAs(User::factory()->admin($this->restaurante)->create());
    }

    private function conPlan(?int $maxMesas): void
    {
        $plan = Plan::create(['nombre' => 'Básico', 'precio_mensual' => 39, 'max_mesas' => $maxMesas]);
        $this->restaurante->update(['plan_id' => $plan->id]);
    }

    private function mesas(int $cantidad, bool $activa = true, ?Area $area = null): void
    {
        foreach (range(1, $cantidad) as $numero) {
            Mesa::factory()->for($area ?? $this->salon)->create([
                'restaurante_id' => $this->restaurante->id,
                'codigo' => ($activa ? 'A' : 'I').($area?->id ?? 0).'-'.$numero,
                'activa' => $activa,
            ]);
        }
    }

    private function crear(string $codigo): TestResponse
    {
        return $this->postJson(route('panel.mesas.store'), ['codigo' => $codigo, 'capacidad' => 4, 'area_id' => $this->salon->id]);
    }

    public function test_creating_stops_at_the_plan_limit(): void
    {
        $this->conPlan(3);
        $this->mesas(2);

        $this->crear('Nueva 1')->assertOk();
        $this->crear('Nueva 2')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mesas' => 'Tu plan Básico permite hasta 3 mesas activas y ya tienes 3. Desactiva una o mejora tu plan.']);

        $this->assertSame(3, Mesa::where('activa', true)->count());
    }

    public function test_deactivated_tables_do_not_count(): void
    {
        $this->conPlan(2);
        $this->mesas(1);
        $this->mesas(5, activa: false);

        $this->crear('Nueva 1')->assertOk();
        $this->crear('Nueva 2')->assertUnprocessable()->assertJsonValidationErrors('mesas');
    }

    public function test_tables_of_a_deactivated_area_still_count(): void
    {
        $this->conPlan(2);
        $terraza = Area::factory()->create(['restaurante_id' => $this->restaurante->id, 'nombre' => 'Terraza', 'activa' => false]);
        $this->mesas(2, area: $terraza);

        $this->crear('Nueva 1')->assertUnprocessable()->assertJsonValidationErrors('mesas');
    }

    public function test_add_several_says_how_many_fit_and_creates_none(): void
    {
        $this->conPlan(5);
        $this->mesas(3);

        $this->postJson(route('panel.mesas.varias'), ['area_id' => $this->salon->id, 'prefijo' => 'T', 'desde' => 1, 'hasta' => 4, 'capacidad' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mesas' => 'Tu plan Básico permite hasta 5 mesas activas y ya tienes 3: puedes agregar 2 más.']);
        $this->assertSame(3, Mesa::count());

        $this->postJson(route('panel.mesas.varias'), ['area_id' => $this->salon->id, 'prefijo' => 'T', 'desde' => 1, 'hasta' => 2, 'capacidad' => 2])->assertOk();
        $this->assertSame(5, Mesa::where('activa', true)->count());
    }

    public function test_reactivating_respects_the_limit(): void
    {
        $this->conPlan(2);
        $this->mesas(2);
        $inactiva = Mesa::factory()->for($this->salon)->create(['restaurante_id' => $this->restaurante->id, 'codigo' => 'Vieja', 'activa' => false]);

        $this->patchJson(route('panel.mesas.activa', $inactiva), ['activa' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mesas' => 'Tu plan Básico permite hasta 2 mesas activas y ya tienes 2. Desactiva una o mejora tu plan.']);
        $this->assertFalse($inactiva->fresh()->activa);

        $this->patchJson(route('panel.mesas.activa', Mesa::where('activa', true)->first()), ['activa' => 0])->assertOk();
        $this->patchJson(route('panel.mesas.activa', $inactiva), ['activa' => 1])->assertOk();
        $this->assertTrue($inactiva->fresh()->activa);
    }

    public function test_editing_a_table_at_the_limit_is_allowed(): void
    {
        $this->conPlan(1);
        $this->mesas(1);

        $this->putJson(route('panel.mesas.update', Mesa::sole()), ['codigo' => 'Renombrada', 'capacidad' => 6, 'area_id' => $this->salon->id])->assertOk();
    }

    public function test_a_plan_without_limit_or_no_plan_has_no_cap(): void
    {
        $this->conPlan(null);
        $this->mesas(40);
        $this->crear('Nueva 1')->assertOk();

        $this->restaurante->update(['plan_id' => null]);
        $this->postJson(route('panel.mesas.varias'), ['area_id' => $this->salon->id, 'desde' => 1, 'hasta' => 50, 'capacidad' => 2])->assertOk();

        $this->assertSame(91, Mesa::where('activa', true)->count());
    }

    public function test_the_admin_sees_the_usage_of_the_plan(): void
    {
        $this->conPlan(10);
        $this->mesas(3);
        $this->mesas(2, activa: false);

        $this->get(route('panel.mesas.index', ['editar' => 1]))->assertOk()->assertSee('3 de 10 mesas activas de tu plan Básico');

        $this->restaurante->plan->update(['max_mesas' => null]);
        $this->actingAs(User::where('restaurante_id', $this->restaurante->id)->sole());
        $this->get(route('panel.mesas.index', ['editar' => 1]))->assertOk()->assertSee('3 mesas activas')->assertDontSee('de tu plan');
    }
}
