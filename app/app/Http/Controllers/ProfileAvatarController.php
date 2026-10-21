<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileAvatarController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function show(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $path = $user->avatar_path;
        abort_unless($path && str_starts_with($path, 'avatars/'.$user->id.'/') && Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function upload(Request $request, AuditEvents $audit): RedirectResponse
    {
        $validated = $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
        ], [
            'avatar.mimes' => 'Escolha uma imagem JPG, PNG ou WebP.',
            'avatar.max' => 'A imagem deve ter no máximo 2 MB.',
            'avatar.dimensions' => 'A imagem deve ter até 2048 × 2048 pixels.',
        ]);

        $user = $request->user();
        $path = $validated['avatar']->store('avatars/'.$user->id, 'local');
        if (! $path) {
            throw new RuntimeException('Não foi possível salvar a foto de perfil.');
        }

        $previous = $user->avatar_path;
        try {
            $user->forceFill(['avatar_path' => $path, 'avatar_preset' => null])->save();
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
        $audit->record('profile.avatar.updated', 'user', (string) $user->id, $user->name, 'success',
            ['mode' => 'photo'], $user->id, $request->ip());

        return redirect()->route('profile.edit')->with('success', 'Foto de perfil atualizada.');
    }

    public function preset(Request $request, AuditEvents $audit): RedirectResponse
    {
        $validated = $request->validate(['avatar_preset' => ['required', Rule::in(User::AVATAR_PRESETS)]]);
        $user = $request->user();
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => null, 'avatar_preset' => $validated['avatar_preset']])->save();
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
        $audit->record('profile.avatar.updated', 'user', (string) $user->id, $user->name, 'success',
            ['mode' => 'preset', 'preset' => $validated['avatar_preset']], $user->id, $request->ip());

        return redirect()->route('profile.edit')->with('success', 'Avatar atualizado.');
    }

    public function remove(Request $request, AuditEvents $audit): RedirectResponse
    {
        $user = $request->user();
        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => null, 'avatar_preset' => null])->save();
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
        $audit->record('profile.avatar.removed', 'user', (string) $user->id, $user->name, 'success',
            [], $user->id, $request->ip());

        return redirect()->route('profile.edit')->with('success', 'Avatar removido.');
    }
}
