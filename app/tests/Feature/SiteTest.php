<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_sites(): void
    {
        $this->get('/sites')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_sites(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/sites')
            ->assertOk()
            ->assertSee('Sites / POPs');
    }

    public function test_sites_index_shows_empty_state_and_create_action(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/sites')
            ->assertOk()
            ->assertSee('class="page-header"', false)
            ->assertSeeText('0 locais cadastrados')
            ->assertSee('class="empty-state__title"', false)
            ->assertSee('Cadastrar primeiro Site / POP');
    }

    public function test_sites_index_shows_entity_details_and_compact_actions(): void
    {
        $site = Site::create([
            'name' => 'POP Batistini',
            'code' => 'SBC-01',
            'location' => 'São Bernardo do Campo',
            'description' => 'Parque Imigrantes SEDE',
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/sites')
            ->assertOk()
            ->assertSeeText('1 local cadastrado')
            ->assertSee('class="table-shell"', false)
            ->assertSee('class="entity-cell__title"', false)
            ->assertSee('POP Batistini')
            ->assertSee('Parque Imigrantes SEDE')
            ->assertSee('SBC-01')
            ->assertSee('São Bernardo do Campo')
            ->assertSee(route('sites.edit', $site))
            ->assertSee(route('sites.destroy', $site));
    }

    public function test_authenticated_user_can_create_site(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post('/sites', [
                'name' => 'POP Suzano',
                'code' => 'pop-suzano',
                'location' => 'Suzano - SP',
                'description' => 'POP principal',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'name' => 'POP Suzano',
            'code' => 'POP-SUZANO',
            'location' => 'Suzano - SP',
            'is_active' => true,
        ]);
    }

    public function test_site_code_must_be_unique(): void
    {
        $user = User::factory()->create();

        Site::create([
            'name' => 'POP 1',
            'code' => 'POP-SP',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/sites/create')
            ->post('/sites', [
                'name' => 'POP 2',
                'code' => 'pop-sp',
                'is_active' => '1',
            ]);

        $response
            ->assertRedirect('/sites/create')
            ->assertSessionHasErrors('code');
    }

    public function test_authenticated_user_can_update_site(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Antigo',
            'code' => 'OLD',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->put("/sites/{$site->id}", [
                'name' => 'POP Atualizado',
                'code' => 'new',
                'location' => 'São Paulo - SP',
                'is_active' => '0',
            ])
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'name' => 'POP Atualizado',
            'code' => 'NEW',
            'is_active' => false,
        ]);
    }

    public function test_authenticated_user_can_delete_site(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Temporário',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->delete("/sites/{$site->id}")
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseMissing('sites', [
            'id' => $site->id,
        ]);
    }
}
