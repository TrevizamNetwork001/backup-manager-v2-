<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('foto.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='
        ));
    }

    public function test_user_can_upload_view_and_remove_own_photo(): void
    {
        Storage::fake('local');
        $user = User::factory()->operator()->create(['name' => 'Operador Exemplo']);
        $this->actingAs($user);

        $this->get(route('profile.edit'))->assertOk()->assertSee('Escolher um avatar')
            ->assertSee('Abrir meu perfil');
        $this->post(route('profile.avatar.upload'), ['avatar' => $this->png()])
            ->assertRedirect(route('profile.edit'));

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->get(route('profile.avatar.show'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('profile.edit'))->assertOk()->assertSee(route('profile.avatar.show'))
            ->assertSee('profile-current__avatar--photo')->assertSee('A foto será mostrada inteira.');
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('profile.avatar.show'))->assertNotFound();
        $this->actingAs($user);
        $this->delete(route('profile.avatar.remove'))->assertRedirect(route('profile.edit'));
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
        $this->get(route('profile.avatar.show'))->assertNotFound();
    }

    public function test_user_can_choose_preset_and_replace_photo(): void
    {
        Storage::fake('local');
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $this->post(route('profile.avatar.upload'), ['avatar' => $this->png()])->assertRedirect();
        $path = $user->fresh()->avatar_path;

        $this->patch(route('profile.avatar.preset'), ['avatar_preset' => 'verde'])
            ->assertRedirect(route('profile.edit'));
        $this->assertSame('verde', $user->fresh()->avatar_preset);
        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
        $this->get(route('dashboard'))->assertOk()->assertSee('assets/avatars/verde.png');
        $this->get(route('profile.edit'))->assertOk()->assertSee('Tartaruga');
        $this->patch(route('profile.avatar.preset'), ['avatar_preset' => 'invalido'])
            ->assertSessionHasErrors('avatar_preset');
        $this->assertSame('verde', $user->fresh()->avatar_preset);
    }

    public function test_invalid_upload_does_not_change_avatar(): void
    {
        Storage::fake('local');
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $this->post(route('profile.avatar.upload'), [
            'avatar' => UploadedFile::fake()->createWithContent('arquivo.svg', '<svg></svg>'),
        ])->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_guest_cannot_open_profile_or_avatar(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->get(route('profile.avatar.show'))->assertRedirect(route('login'));
        $this->post(route('profile.avatar.upload'))->assertRedirect(route('login'));
    }
}
