<?php

namespace Tests\Feature\Configuracion;

use App\Models\Restaurante;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConfiguracionGeneralTest extends TestCase
{
    use RefreshDatabase;

    private Restaurante $restaurante;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->restaurante = Restaurante::factory()->create(['slug' => 'el-buen-sabor', 'nombre' => 'El Buen Sabor']);
        $this->admin = User::factory()->admin($this->restaurante)->create();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datos(array $cambios = []): array
    {
        return [
            'nombre' => 'El Buen Sabor',
            'descripcion' => 'Pollos a la brasa desde 1998.',
            'telefono' => '987 654 321',
            'email' => 'hola@buensabor.pe',
            'direccion' => 'Jr. Huallayco 845',
            'ciudad' => 'Huánuco',
            ...$cambios,
        ];
    }

    public function test_admin_saves_the_general_data_and_the_slug_stays_read_only(): void
    {
        $rubro = Rubro::create(['nombre' => 'Pollería']);

        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.general.update'), $this->datos(['rubro_id' => $rubro->id, 'slug' => 'otro-slug']))
            ->assertOk()
            ->assertJsonPath('mensaje', 'Se guardaron los datos del local.');

        $restaurante = $this->restaurante->fresh();
        $this->assertSame('Pollos a la brasa desde 1998.', $restaurante->descripcion);
        $this->assertSame('987654321', $restaurante->telefono);
        $this->assertSame($rubro->id, $restaurante->rubro_id);
        $this->assertSame('el-buen-sabor', $restaurante->slug);
    }

    public function test_the_page_shows_the_storefront_link_with_a_copy_button(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.configuracion.general'))
            ->assertOk()
            ->assertSee(route('vitrina.show', 'el-buen-sabor'))
            ->assertSee('Copiar enlace')
            ->assertSee('no se puede cambiar')
            ->assertSeeInOrder(['data-guardar', 'Guardar cambios'], false);
    }

    public function test_validation_errors_come_back_per_field(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('panel.configuracion.general.update'), $this->datos(['nombre' => '', 'telefono' => '12345', 'email' => 'no-es-correo', 'rubro_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'telefono', 'email', 'rubro_id'])
            ->assertJsonPath('errors.telefono.0', 'Escribe un celular peruano de 9 dígitos que empiece con 9.');
    }

    public function test_without_javascript_it_redirects_back_with_a_message(): void
    {
        $this->actingAs($this->admin)
            ->put(route('panel.configuracion.general.update'), $this->datos())
            ->assertRedirect(route('panel.configuracion.general'))
            ->assertSessionHas('status');
    }

    public function test_logo_is_stored_with_a_random_name_and_the_old_one_is_deleted_on_replace(): void
    {
        $this->actingAs($this->admin)
            ->put(route('panel.configuracion.general.update'), $this->datos(['logo' => UploadedFile::fake()->image('mi logo.png', 300, 300)]), ['Accept' => 'application/json'])
            ->assertOk();

        $primero = $this->restaurante->fresh()->logo;
        $this->assertStringStartsWith('logos/', $primero);
        $this->assertStringNotContainsString('mi logo', $primero);
        Storage::disk('public')->assertExists($primero);

        $this->put(route('panel.configuracion.general.update'), $this->datos(['logo' => UploadedFile::fake()->image('nuevo.webp', 300, 300)]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('logo', $this->restaurante->fresh()->logoUrl());

        $segundo = $this->restaurante->fresh()->logo;
        $this->assertNotSame($primero, $segundo);
        Storage::disk('public')->assertExists($segundo);
        Storage::disk('public')->assertMissing($primero);
    }

    public function test_removing_the_logo_deletes_the_file(): void
    {
        $this->actingAs($this->admin)
            ->put(route('panel.configuracion.general.update'), $this->datos(['logo' => UploadedFile::fake()->image('logo.jpg')]), ['Accept' => 'application/json']);

        $logo = $this->restaurante->fresh()->logo;

        $this->putJson(route('panel.configuracion.general.update'), $this->datos(['quitar_logo' => '1']))
            ->assertOk()
            ->assertJsonPath('logo', null);

        $this->assertNull($this->restaurante->fresh()->logo);
        Storage::disk('public')->assertMissing($logo);
    }

    public function test_logo_must_be_an_image_of_up_to_2_mb(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('panel.configuracion.general.update'), $this->datos(['logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.logo.0', 'El logo debe ser una imagen JPG, PNG o WEBP.');

        $this->put(route('panel.configuracion.general.update'), $this->datos(['logo' => UploadedFile::fake()->image('logo.gif')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');

        $this->put(route('panel.configuracion.general.update'), $this->datos(['logo' => UploadedFile::fake()->image('logo.png')->size(2049)]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.logo.0', 'El logo debe pesar como máximo 2 MB.');

        $this->assertNull($this->restaurante->fresh()->logo);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_user_text_is_escaped_on_the_page(): void
    {
        $this->restaurante->update(['descripcion' => '<script>alert(1)</script>']);

        $this->actingAs($this->admin)
            ->get(route('panel.configuracion.general'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
